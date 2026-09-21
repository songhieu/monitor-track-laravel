<?php

namespace MonitorTrack\Tests\Unit;

use MonitorTrack\Client;
use MonitorTrack\Stats;
use MonitorTrack\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

class HttpTransportTest extends TestCase
{
    /** @var list<resource> */
    private array $processes = [];

    private ?string $dir = null;

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            @proc_terminate($process);
            @proc_close($process);
        }
        $this->processes = [];

        if ($this->dir !== null) {
            array_map('unlink', glob($this->dir.'/*') ?: []);
            @rmdir($this->dir);
        }
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Starts `php -S` with the ingest recorder; returns the endpoint.
     */
    private function ingestServer(int $status = 202): string
    {
        $this->dir = sys_get_temp_dir().'/mt-ingest-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $port = self::freePort();

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/../Fixtures/ingest-server.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['MT_TEST_DIR' => $this->dir, 'MT_TEST_STATUS' => (string) $status, 'XDEBUG_MODE' => 'off', 'PATH' => getenv('PATH')],
        );
        $this->processes[] = $process;

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);

                return "http://127.0.0.1:{$port}";
            }
            usleep(50_000);
        }

        $this->markTestSkipped('could not start php -S');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requests(): array
    {
        $files = glob($this->dir.'/*.json') ?: [];
        sort($files);

        return array_map(fn ($f) => json_decode(file_get_contents($f), true), $files);
    }

    public function test_batches_are_posted_as_gzip_ndjson(): void
    {
        $endpoint = $this->ingestServer(202);
        $client = new Client(['app' => 'billing-api', 'transport' => 'http', 'endpoint' => $endpoint.'/', 'token' => 'src_tok']);
        $this->assertInstanceOf(HttpTransport::class, $client->transport());

        for ($i = 0; $i < 3; $i++) {
            $client->log('info', "event {$i}");
        }
        $client->captureException(new \RuntimeException('boom'));
        $this->assertSame(4, $client->transport()->pending());
        $this->assertSame([], $this->requests(), 'nothing is sent before flush');

        $client->flush();

        $requests = $this->requests();
        $this->assertCount(1, $requests);
        $request = $requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('/api/v1/logs/ingest', $request['uri']);
        $this->assertSame('Bearer src_tok', $request['authorization']);
        $this->assertSame('application/x-ndjson', $request['content_type']);
        $this->assertSame('gzip', $request['content_encoding']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $request['idempotency_key']);

        $lines = explode("\n", rtrim($request['body'], "\n"));
        $this->assertCount(4, $lines);
        foreach ($lines as $line) {
            $this->assertStringStartsWith('{"_mt":1,', $line);
        }
        $this->assertSame(['emitted' => 4, 'dropped' => 0, 'sampled' => 0, 'errors' => 0], $client->stats());
        $this->assertSame(0, $client->transport()->pending());
    }

    public function test_503_drops_the_batch_without_retrying(): void
    {
        $endpoint = $this->ingestServer(503);
        $client = new Client(['transport' => 'http', 'endpoint' => $endpoint, 'token' => 't']);

        $client->log('info', 'a');
        $client->log('info', 'b');
        $client->flush();

        $this->assertCount(1, $this->requests(), 'no retry on 503');
        $this->assertSame(2, $client->stats()['dropped']);
        $this->assertSame(0, $client->transport()->pending());
    }

    public function test_closed_port_never_throws_and_counts_dropped(): void
    {
        $port = self::freePort();
        $client = new Client(['transport' => 'http', 'endpoint' => "http://127.0.0.1:{$port}", 'token' => 't']);

        $client->log('info', 'a');
        $client->log('info', 'b');
        $client->log('info', 'c');

        $start = microtime(true);
        $client->flush();
        $elapsed = microtime(true) - $start;

        $this->assertSame(3, $client->stats()['emitted']);
        $this->assertSame(3, $client->stats()['dropped']);
        $this->assertSame(1, $client->stats()['errors']);
        $this->assertLessThan(2.0, $elapsed);
    }

    public function test_hanging_server_returns_within_timeout(): void
    {
        // Accepts connections (kernel backlog) but never answers.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($server, false);
        $client = new Client(['transport' => 'http', 'endpoint' => "http://{$name}", 'token' => 't']);

        $client->log('info', 'a');

        $start = microtime(true);
        $client->flush(2.0);
        $elapsed = microtime(true) - $start;
        fclose($server);

        $this->assertLessThan(2.0, $elapsed, 'request timeout is 1 s and timeouts are not retried');
        $this->assertSame(1, $client->stats()['dropped']);
    }

    public function test_buffer_cap_drops_newest(): void
    {
        $calls = [];
        $stats = new Stats;
        $transport = new HttpTransport('http://example.invalid', 'tok', 1000, $stats, 500, 1000,
            function (string $url, array $headers, string $body) use (&$calls) {
                $calls[] = ['url' => $url, 'headers' => $headers, 'lines' => explode("\n", rtrim(gzdecode($body), "\n"))];

                return ['status' => 202, 'retryable' => false];
            });
        $client = new Client(['app' => 'x'], $transport, $stats);

        for ($i = 0; $i < 1005; $i++) {
            $client->log('info', "event {$i}");
        }

        $this->assertSame(1000, $transport->pending());
        $this->assertSame(['emitted' => 1000, 'dropped' => 5, 'sampled' => 0, 'errors' => 0], $client->stats());

        $client->flush();

        $this->assertCount(2, $calls, '500 lines per batch');
        $this->assertCount(500, $calls[0]['lines']);
        $this->assertCount(500, $calls[1]['lines']);
        $this->assertSame('event 0', json_decode($calls[0]['lines'][0], true)['message']);
        $this->assertSame('event 999', json_decode($calls[1]['lines'][499], true)['message']);
        $this->assertSame('http://example.invalid/api/v1/logs/ingest', $calls[0]['url']);
        $this->assertContains('Content-Encoding: gzip', $calls[0]['headers']);
        $this->assertContains('Authorization: Bearer tok', $calls[0]['headers']);
    }

    public function test_connection_error_is_retried_once_with_the_same_idempotency_key(): void
    {
        $calls = [];
        $stats = new Stats;
        $transport = new HttpTransport('http://x', 't', 100, $stats, 500, 1000,
            function (string $url, array $headers) use (&$calls) {
                $calls[] = $headers;

                return ['status' => 0, 'retryable' => true];
            });
        $client = new Client([], $transport, $stats);
        $client->log('info', 'a');
        $client->flush();

        $this->assertCount(2, $calls);
        $key = fn (array $headers) => current(array_filter($headers, fn ($h) => str_starts_with($h, 'Idempotency-Key:')));
        $this->assertSame($key($calls[0]), $key($calls[1]));
        $this->assertSame(1, $client->stats()['dropped']);
    }

    public function test_flush_if_due_waits_for_interval_or_batch_size(): void
    {
        $posted = 0;
        $transport = new HttpTransport('http://x', 't', 1000, new Stats, 500, 1000,
            function (string $url, array $headers, string $body) use (&$posted) {
                $posted += substr_count(gzdecode($body), "\n");

                return ['status' => 202, 'retryable' => false];
            });
        $client = new Client([], $transport);

        $client->log('info', 'a');
        $client->flushIfDue();
        $this->assertSame(0, $posted, 'not due yet');

        for ($i = 0; $i < 499; $i++) {
            $client->log('info', 'b');
        }
        $client->flushIfDue();
        $this->assertSame(500, $posted, '500 buffered events trigger a flush');
    }

    public function test_sender_exceptions_are_swallowed(): void
    {
        $stats = new Stats;
        $transport = new HttpTransport('http://x', 't', 10, $stats, 500, 1000, function () {
            throw new \RuntimeException('network exploded');
        });
        $client = new Client([], $transport, $stats);
        $client->log('info', 'a');
        $client->flush();

        $this->assertSame(1, $client->stats()['dropped']);
    }
}
