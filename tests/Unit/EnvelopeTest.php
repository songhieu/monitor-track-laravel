<?php

namespace MonitorTrack\Tests\Unit;

use MonitorTrack\Client;
use MonitorTrack\Support\EnvelopeEncoder;
use MonitorTrack\Transport\FileTransport;
use MonitorTrack\Transport\MemoryTransport;
use MonitorTrack\Transport\StreamTransport;
use PHPUnit\Framework\TestCase;

class EnvelopeTest extends TestCase
{
    private function client(array $options = []): array
    {
        $memory = new MemoryTransport;
        $client = new Client($options + ['app' => 'billing-api', 'host' => 'billing-api-7d9f8c6b5-x2kqp'], $memory);

        return [$client, $memory];
    }

    public function test_first_key_is_mt_and_keys_follow_wire_order(): void
    {
        [$client, $memory] = $this->client(['env' => 'production', 'release' => '2026.09.21-2']);

        $client->record('log', 'warning', 'Payment retry scheduled', [
            'context' => ['order_id' => 991],
            'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'route' => 'POST /api/v1/payments',
            'user_id' => 1823,
        ]);

        $line = $memory->lines()[0];
        $this->assertStringStartsWith('{"_mt":1,"type":"log","ts":"', $line);
        $this->assertStringEndsWith("}\n", $line);
        $this->assertSame(1, substr_count($line, "\n"));

        $event = json_decode($line, true);
        $this->assertSame(
            ['_mt', 'type', 'ts', 'level', 'message', 'app', 'env', 'release', 'host', 'pid', 'trace_id', 'user_id', 'route', 'context'],
            array_keys($event),
        );
        $this->assertSame('warning', $event['level']);
        $this->assertSame('1823', $event['user_id']);
        $this->assertSame(getmypid(), $event['pid']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}([+-]\d{2}:\d{2}|Z)$/', $event['ts']);
    }

    public function test_empty_fields_are_omitted(): void
    {
        [$client, $memory] = $this->client();

        $client->log('info', 'hello', []);
        $event = $memory->events()[0];

        $this->assertSame(['_mt', 'type', 'ts', 'level', 'message', 'app', 'host', 'pid'], array_keys($event));
        $this->assertStringNotContainsString('null', $memory->lines()[0]);
        $this->assertStringNotContainsString('""', $memory->lines()[0]);
    }

    public function test_job_cron_heartbeat_payloads_and_messages(): void
    {
        [$client, $memory] = $this->client();

        $job = ['queue' => 'emails', 'connection' => 'redis', 'class' => 'App\\Jobs\\SendInvoiceEmail', 'id' => 'abc', 'attempts' => 1, 'timeout_ms' => 10000];
        $client->job('start', $job);
        $client->job('done', $job + ['runtime_ms' => 1820]);
        $client->job('failed', $job + ['runtime_ms' => 5]);
        $client->job('retry', $job + ['runtime_ms' => 7]);
        $client->cron('start', ['name' => 'invoices:send-reminders', 'expr' => '0 9 * * 1-5', 'tz' => 'Asia/Ho_Chi_Minh']);
        $client->cron('fail', ['name' => 'invoices:send-reminders', 'expr' => '0 9 * * 1-5', 'duration_ms' => 14000, 'output' => 'boom']);
        $client->heartbeat(['queues' => ['emails'], 'state' => 'busy', 'job' => 'App\\Jobs\\SendInvoiceEmail', 'job_started_at' => '2026-09-21T14:31:05.120+07:00', 'memory_mb' => 96.5, 'processed' => 540]);
        $client->heartbeat(['queues' => ['emails'], 'state' => 'idle', 'job' => 'ignored when idle', 'memory_mb' => 96.0]);

        $events = $memory->events();

        $this->assertSame('Job started: App\\Jobs\\SendInvoiceEmail', $events[0]['message']);
        $this->assertSame(['queue', 'connection', 'class', 'id', 'status', 'attempts', 'timeout_ms'], array_keys($events[0]['job']));
        $this->assertSame(['info', 'Job done: App\\Jobs\\SendInvoiceEmail (1820ms)'], [$events[1]['level'], $events[1]['message']]);
        $this->assertSame(1820, $events[1]['job']['runtime_ms']);
        $this->assertSame(['error', 'Job failed: App\\Jobs\\SendInvoiceEmail (5ms)'], [$events[2]['level'], $events[2]['message']]);
        $this->assertSame(['warning', 'Job released for retry: App\\Jobs\\SendInvoiceEmail (7ms)'], [$events[3]['level'], $events[3]['message']]);

        $this->assertSame(['name' => 'invoices:send-reminders', 'expr' => '0 9 * * 1-5', 'tz' => 'Asia/Ho_Chi_Minh', 'phase' => 'start'], $events[4]['cron']);
        $this->assertSame('error', $events[5]['level']);
        $this->assertSame('Scheduled task failed: invoices:send-reminders (exit 1, 14000ms)', $events[5]['message']);
        $this->assertSame(1, $events[5]['cron']['exit_code']);
        $this->assertSame('boom', $events[5]['cron']['output']);

        $this->assertSame('debug', $events[6]['level']);
        $this->assertSame('Worker heartbeat: busy', $events[6]['message']);
        $this->assertSame(['queues', 'state', 'job', 'job_started_at', 'memory_mb', 'processed'], array_keys($events[6]['heartbeat']));
        $this->assertArrayNotHasKey('job', $events[7]['heartbeat']);
        $this->assertStringContainsString('"memory_mb":96.0', $memory->lines()[7]);

        foreach ($events as $event) {
            foreach (['job', 'cron', 'heartbeat', 'exception'] as $key) {
                $this->assertSame($key === $event['type'], array_key_exists($key, $event), "{$event['type']} carries only its own payload");
            }
        }
    }

    public function test_context_is_masked_at_any_depth(): void
    {
        [$client, $memory] = $this->client();

        $client->log('info', 'masked', [
            'password' => 'hunter2',
            'user' => ['email' => 'a@b.c', 'Api-Key' => 'k', 'card_number' => '4111'],
            'headers' => ['Authorization' => 'Bearer x', 'X-CSRF-TOKEN' => 't', 'Cookie' => 'c', 'accept' => 'json'],
            'cvv' => 123,
            'deep' => ['a' => ['b' => ['c' => ['d' => ['e' => 'too deep']]]]],
            'object' => new \ArrayObject([1]),
            'date' => new \DateTimeImmutable('2026-09-21T14:31:07.412+07:00'),
            'list' => ['x', 'y'],
        ]);

        $context = $memory->events()[0]['context'];
        $this->assertSame('***', $context['password']);
        $this->assertSame(['email' => 'a@b.c', 'Api-Key' => '***', 'card_number' => '***'], $context['user']);
        $this->assertSame(['Authorization' => '***', 'X-CSRF-TOKEN' => '***', 'Cookie' => '***', 'accept' => 'json'], $context['headers']);
        $this->assertSame('***', $context['cvv']);
        $this->assertSame(['a' => ['b' => ['c' => ['d' => '[depth]']]]], $context['deep']);
        $this->assertSame('[ArrayObject]', $context['object']);
        $this->assertSame('2026-09-21T14:31:07.412+07:00', $context['date']);
        $this->assertSame(['x', 'y'], $context['list']);
    }

    public function test_list_context_is_still_a_json_object(): void
    {
        [$client, $memory] = $this->client();
        $client->log('info', 'list', ['a', 'b']);

        $this->assertStringContainsString('"context":{"0":"a","1":"b"}', $memory->lines()[0]);
    }

    public function test_oversized_events_are_truncated_to_one_line_under_32k(): void
    {
        [$client, $memory] = $this->client();

        $big = str_repeat("line with \"quotes\" and newline\n", 5000);
        $client->log('error', $big, ['blob' => $big, 'nested' => ['blob' => $big]]);
        $client->cron('fail', ['name' => 'x', 'output' => 'HEAD'.str_repeat('é', 20000).'TAIL']);

        foreach ($memory->lines() as $line) {
            $this->assertLessThanOrEqual(EnvelopeEncoder::HARD_LIMIT, strlen($line));
            $this->assertSame(1, substr_count($line, "\n"));
            $this->assertStringStartsWith('{"_mt":1,', $line);
            $this->assertNotNull(json_decode($line, true), 'valid JSON');
        }

        $log = $memory->events()[0];
        $this->assertStringEndsWith('…', $log['message']);
        $this->assertLessThanOrEqual(2048, strlen($log['message']));
        $this->assertLessThanOrEqual(256, strlen($log['context']['blob']));

        $cron = $memory->events()[1];
        $this->assertStringStartsWith('…', $cron['cron']['output']);
        $this->assertStringEndsWith('TAIL', $cron['cron']['output']);
        $this->assertTrue(mb_check_encoding($cron['cron']['output'], 'UTF-8'));
    }

    public function test_context_is_replaced_when_still_over_the_hard_limit(): void
    {
        // 300 keys × 256 bytes is still ~80 KiB after step 2, so step 3
        // replaces the context.
        $encoded = EnvelopeEncoder::encode([
            '_mt' => 1, 'type' => 'log', 'ts' => 'x', 'level' => 'info', 'message' => 'm', 'pid' => 1,
            'context' => array_fill_keys(array_map(fn ($i) => "key{$i}", range(1, 300)), str_repeat('y', 300)),
        ]);
        $this->assertNotNull($encoded);
        $this->assertLessThanOrEqual(EnvelopeEncoder::HARD_LIMIT, strlen($encoded));
        $this->assertStringContainsString('"context":{"_truncated":true}', $encoded);

        // Through the client the masker keeps 100 items per level, which fits
        // after step 2.
        [$client, $memory] = $this->client();
        $client->log('info', 'wide', array_fill_keys(array_map(fn ($i) => "k{$i}", range(1, 400)), str_repeat('x', 1000)));
        $this->assertCount(100, $memory->events()[0]['context']);
        $this->assertLessThanOrEqual(EnvelopeEncoder::HARD_LIMIT, strlen($memory->lines()[0]));
    }

    public function test_event_that_cannot_fit_is_dropped_and_counted(): void
    {
        [$client, $memory] = $this->client();
        $client->job('start', ['class' => str_repeat('C', 40000), 'id' => '1']);

        $this->assertSame([], $memory->lines());
        $this->assertSame(1, $client->stats()['dropped']);
    }

    public function test_invalid_utf8_never_breaks_encoding(): void
    {
        [$client, $memory] = $this->client();
        $client->log('info', "bad \xB1\x31 bytes", ['v' => "\xFF"]);

        $event = $memory->events()[0];
        $this->assertStringContainsString("\u{FFFD}", $event['message']);
    }

    public function test_sampling_applies_to_logs_only(): void
    {
        [$client, $memory] = $this->client(['sample_rate' => 0]);

        $client->log('info', 'sampled out');
        $client->captureException(new \RuntimeException('kept'));
        $client->job('start', ['class' => 'J', 'id' => '1']);
        $client->cron('start', ['name' => 'c']);
        $client->heartbeat([]);

        $this->assertSame(['exception', 'job', 'cron', 'heartbeat'], array_column($memory->events(), 'type'));
        $this->assertSame(['emitted' => 4, 'dropped' => 0, 'sampled' => 1, 'errors' => 0], $client->stats());
    }

    public function test_disabled_client_is_a_no_op(): void
    {
        $memory = new MemoryTransport;
        $client = new Client(['enabled' => 'false'], $memory);

        $client->log('info', 'x');
        $client->captureException(new \RuntimeException('x'));
        $client->job('start', ['class' => 'J']);
        $client->cron('start', ['name' => 'c']);
        $client->heartbeat([]);
        $client->flush();

        $this->assertFalse($client->isEnabled());
        $this->assertSame([], $memory->lines());
        $this->assertSame(['emitted' => 0, 'dropped' => 0, 'sampled' => 0, 'errors' => 0], $client->stats());
        $this->assertFalse(Client::disabled()->isEnabled());
    }

    public function test_track_helpers_emit_pairs_and_rethrow(): void
    {
        [$client, $memory] = $this->client();

        $this->assertSame(42, $client->trackJob('reports', 'BuildReport', fn () => 42));
        $this->assertSame('ok', $client->trackCron('reports:nightly', '0 2 * * *', fn () => 'ok', 'UTC'));

        try {
            $client->trackCron('reports:broken', '*/5 * * * *', function () {
                throw new \DomainException('no data');
            });
            $this->fail('exception must propagate');
        } catch (\DomainException $e) {
            $this->assertSame('no data', $e->getMessage());
        }

        $events = $memory->events();
        $this->assertSame('start', $events[0]['job']['status']);
        $this->assertSame('done', $events[1]['job']['status']);
        $this->assertSame($events[0]['job']['id'], $events[1]['job']['id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $events[0]['job']['id']);
        $this->assertSame(['start', 'success'], [$events[2]['cron']['phase'], $events[3]['cron']['phase']]);
        $this->assertSame(0, $events[3]['cron']['exit_code']);
        $this->assertSame(['start', 'fail'], [$events[4]['cron']['phase'], $events[5]['cron']['phase']]);
        $this->assertSame('DomainException: no data', $events[5]['cron']['output']);
        $this->assertSame('exception', $events[6]['type']);
    }

    public function test_broken_transports_never_throw(): void
    {
        $stream = new Client(['app' => 'x'], null);
        $this->assertStringStartsWith('stream php://stderr', $stream->transport()->describe());

        $client = new Client(['app' => 'x']);
        $client->setTransport(new StreamTransport('php://definitely-not-a-stream', new \MonitorTrack\Stats));
        $client->log('info', 'dropped');
        $this->assertSame(1, $client->stats()['dropped']);

        $file = new Client(['app' => 'x', 'transport' => 'file', 'file' => '/dev/null/cannot/create.jsonl']);
        $this->assertInstanceOf(FileTransport::class, $file->transport());
        $file->log('info', 'dropped');
        $file->captureException(new \RuntimeException('dropped'));
        $this->assertSame(2, $file->stats()['dropped']);
        $this->assertGreaterThanOrEqual(1, $file->stats()['errors']);
    }

    public function test_file_transport_appends_one_line_per_event(): void
    {
        $path = sys_get_temp_dir().'/mt-'.bin2hex(random_bytes(4)).'/events.jsonl';
        $client = new Client(['app' => 'x', 'transport' => 'file', 'file' => $path]);

        $client->log('info', 'one');
        $client->log('info', "two\nlines");

        $lines = file($path);
        $this->assertCount(2, $lines);
        $this->assertSame("two\nlines", json_decode($lines[1], true)['message']);

        @unlink($path);
        @rmdir(dirname($path));
    }

    public function test_http_without_endpoint_falls_back_to_stream(): void
    {
        $client = new Client(['transport' => 'http']);
        $this->assertInstanceOf(StreamTransport::class, $client->transport());
    }
}
