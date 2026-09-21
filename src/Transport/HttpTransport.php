<?php

namespace MonitorTrack\Transport;

use MonitorTrack\Stats;

/**
 * Last-resort transport: buffers lines in memory (bounded, drop newest) and
 * POSTs them as gzip NDJSON to {endpoint}/api/v1/logs/ingest.
 *
 * Nothing is sent while a request or job runs. The service provider flushes
 * from app()->terminating() (after the response under FPM; under Octane only
 * when due), from the queue worker's Looping event (every 2 s / 500 lines)
 * and from a shutdown function. A failed batch is dropped: one quick retry on
 * a connection error, no loops.
 *
 * When the ingest can't be reached or answers 429 / 502-504, long-running
 * processes stop trying for a while (5 s, doubling up to 60 s): flushIfDue()
 * keeps buffering (bounded) and flush() drops the buffer at once, so an
 * outage never stalls a worker on every flush.
 */
final class HttpTransport implements Transport
{
    public const BATCH_LINES = 500;

    public const BATCH_BYTES = 4 * 1024 * 1024;

    public const FLUSH_INTERVAL = 2.0;

    private const MIN_BACKOFF = 5.0;

    private const MAX_BACKOFF = 60.0;

    /** @var list<string> */
    private array $buffer = [];

    private float $lastFlush;

    /** No flush before this microtime(true): the ingest failed recently. */
    private float $retryAt = 0.0;

    private float $backoff = 0.0;

    /** @var (callable(string, list<string>, string): array{status:int, retryable:bool})|null */
    private $sender;

    /**
     * @param  (callable(string, list<string>, string): array{status:int, retryable:bool})|null  $sender
     *                                                                                               replaces the curl call (tests)
     */
    public function __construct(
        private string $endpoint,
        private ?string $token,
        private int $maxBuffer,
        private Stats $stats,
        private int $connectTimeoutMs = 500,
        private int $timeoutMs = 1000,
        ?callable $sender = null,
    ) {
        $this->sender = $sender;
        $this->lastFlush = microtime(true);
    }

    public function url(): string
    {
        return rtrim($this->endpoint, '/').'/api/v1/logs/ingest';
    }

    public function send(string $line): bool
    {
        if (count($this->buffer) >= $this->maxBuffer) {
            return false;
        }

        $this->buffer[] = $line;

        return true;
    }

    public function pending(): int
    {
        return count($this->buffer);
    }

    public function flushIfDue(): void
    {
        $n = count($this->buffer);
        if ($n === 0) {
            return;
        }

        $now = microtime(true);
        if ($now < $this->retryAt) {
            return;
        }

        if ($n >= self::BATCH_LINES || $now - $this->lastFlush >= self::FLUSH_INTERVAL) {
            $this->flush($now + 3.0);
        }
    }

    public function flush(?float $deadline = null): void
    {
        $this->lastFlush = microtime(true);

        try {
            if ($this->buffer !== [] && $this->lastFlush < $this->retryAt) {
                // The ingest failed moments ago: give up now instead of
                // waiting for another timeout.
                $this->stats->dropped += count($this->buffer);
                $this->buffer = [];

                return;
            }

            while ($this->buffer !== []) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $this->stats->dropped += count($this->buffer);
                    $this->buffer = [];

                    return;
                }

                $batch = $this->takeBatch();
                $status = $this->post($batch);

                if ($status >= 200 && $status < 300) {
                    $this->backoff = 0.0;

                    continue;
                }

                $this->stats->dropped += count($batch);

                if ($status === 0 || $status === 429 || ($status >= 502 && $status <= 504)) {
                    // Unreachable or overloaded: the rest waits for a flush
                    // after the backoff.
                    $this->backoff = min(self::MAX_BACKOFF, max(self::MIN_BACKOFF, $this->backoff * 2));
                    $this->retryAt = microtime(true) + $this->backoff;

                    return;
                }
            }
        } catch (\Throwable) {
            $this->stats->errors++;
            $this->stats->dropped += count($this->buffer);
            $this->buffer = [];
        }
    }

    /**
     * @return list<string>
     */
    private function takeBatch(): array
    {
        $batch = [];
        $bytes = 0;

        while ($this->buffer !== [] && count($batch) < self::BATCH_LINES) {
            $len = strlen($this->buffer[0]);
            if ($batch !== [] && $bytes + $len > self::BATCH_BYTES) {
                break;
            }
            $batch[] = array_shift($this->buffer);
            $bytes += $len;
        }

        return $batch;
    }

    /**
     * @param  list<string>  $batch
     * @return int the response status, 0 = no response
     */
    private function post(array $batch): int
    {
        $body = implode('', $batch);
        $headers = [
            'Content-Type: application/x-ndjson',
            'Idempotency-Key: '.bin2hex(random_bytes(8)),
            'User-Agent: monitor-track-laravel/1',
        ];

        if ($this->token !== null && $this->token !== '') {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }

        if (function_exists('gzencode')) {
            $gz = @gzencode($body, 6);
            if (is_string($gz)) {
                $body = $gz;
                $headers[] = 'Content-Encoding: gzip';
            }
        }

        $result = $this->request($headers, $body);

        if ($result['status'] === 0 && $result['retryable']) {
            // One quick retry for "connection refused/reset before a response",
            // same Idempotency-Key so the server can deduplicate.
            usleep(200_000);
            $result = $this->request($headers, $body);
        }

        if ($result['status'] === 0) {
            $this->stats->errors++;
        }

        return $result['status'];
    }

    /**
     * @param  list<string>  $headers
     * @return array{status:int, retryable:bool}
     */
    private function request(array $headers, string $body): array
    {
        try {
            if ($this->sender !== null) {
                return ($this->sender)($this->url(), $headers, $body);
            }

            if (function_exists('curl_init')) {
                return $this->curl($headers, $body);
            }

            return $this->stream($headers, $body);
        } catch (\Throwable) {
            return ['status' => 0, 'retryable' => false];
        }
    }

    /**
     * @param  list<string>  $headers
     * @return array{status:int, retryable:bool}
     */
    private function curl(array $headers, string $body): array
    {
        $ch = curl_init($this->url());
        if ($ch === false) {
            return ['status' => 0, 'retryable' => false];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge($headers, ['Expect:']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_NOSIGNAL => true,
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5; the
        // handle is freed when $ch goes out of scope.

        if ($errno !== 0) {
            // 7 couldn't connect, 52 empty reply, 55 send error, 56 recv error
            // (reset). Timeouts (28) are not retried: the server may have the
            // batch and we must not stall the process.
            return ['status' => 0, 'retryable' => in_array($errno, [7, 52, 55, 56], true)];
        }

        return ['status' => $status, 'retryable' => false];
    }

    /**
     * Fallback without ext-curl.
     *
     * @param  list<string>  $headers
     * @return array{status:int, retryable:bool}
     */
    private function stream(array $headers, string $body): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => max(1, $this->timeoutMs / 1000),
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        $http_response_header = null;
        $ok = @file_get_contents($this->url(), false, $context);
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : $http_response_header;

        if ($ok === false && empty($responseHeaders)) {
            return ['status' => 0, 'retryable' => false];
        }

        if (is_array($responseHeaders) && isset($responseHeaders[0])
            && preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHeaders[0], $m)) {
            return ['status' => (int) $m[1], 'retryable' => false];
        }

        return ['status' => 0, 'retryable' => false];
    }

    public function describe(): string
    {
        return 'http '.$this->url();
    }
}
