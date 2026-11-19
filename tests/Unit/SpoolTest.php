<?php

namespace MonitorTrack\Tests\Unit;

use MonitorTrack\Client;
use MonitorTrack\Stats;
use MonitorTrack\Transport\HttpTransport;
use MonitorTrack\Transport\Spool;
use PHPUnit\Framework\TestCase;

class SpoolTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mt-spool-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (array_diff(@scandir($this->dir) ?: [], ['.', '..']) as $file) {
            @unlink($this->dir.'/'.$file);
        }
        @rmdir($this->dir);
    }

    /**
     * A transport whose ingest answers $status (by reference) and records
     * every request: ['key' => Idempotency-Key, 'lines' => [...messages]].
     *
     * @param  list<array{key:string, lines:list<string>}>  $calls
     */
    private function transport(Spool $spool, int &$status, array &$calls, ?Stats $stats = null): HttpTransport
    {
        return new HttpTransport('http://x', 't', 1000, $stats ?? new Stats, 500, 1000,
            function (string $url, array $headers, string $body) use (&$status, &$calls) {
                $key = substr((string) current(array_filter($headers, fn ($h) => str_starts_with($h, 'Idempotency-Key: '))), 17);
                $lines = array_map(fn ($l) => json_decode($l, true)['message'], explode("\n", rtrim(gzdecode($body), "\n")));
                $calls[] = ['key' => $key, 'lines' => $lines];

                return ['status' => $status, 'retryable' => false];
            }, $spool);
    }

    public function test_a_failed_batch_is_sent_again_after_the_next_success_with_its_key(): void
    {
        $status = 0;
        $calls = [];
        $stats = new Stats;
        $transport = $this->transport(new Spool($this->dir), $status, $calls, $stats);
        $client = new Client([], $transport, $stats);

        $client->log('info', 'cron start');
        $client->flush();
        $this->assertSame(0, $client->stats()['dropped']);
        $this->assertSame(1, $transport->spool()->count());

        // Backing off: an explicit flush keeps the buffer in the spool too.
        $client->log('info', 'cron finish');
        $client->flush();
        $this->assertCount(1, $calls, 'no request while backing off');
        $this->assertSame(2, $transport->spool()->count());

        // Back online.
        $status = 202;
        $this->setPrivate($transport, 'retryAt', 0.0);
        $client->log('info', 'next');
        $client->flush();

        $this->assertSame(['next'], $calls[1]['lines']);
        $this->assertSame(['cron start'], $calls[2]['lines'], 'oldest spooled batch first');
        $this->assertSame($calls[0]['key'], $calls[2]['key'], 'resent with the original Idempotency-Key');
        $this->assertCount(3, $calls, 'one spooled batch per flush');
        $this->assertSame(1, $transport->spool()->count());

        $client->log('info', 'later');
        $client->flush();
        $this->assertSame(['cron finish'], $calls[4]['lines']);
        $this->assertSame(0, $transport->spool()->count());
        $this->assertSame(0, $client->stats()['dropped']);
    }

    public function test_another_process_sends_the_spooled_batch(): void
    {
        $down = 503;
        $up = 202;
        $callsA = $callsB = [];
        $a = new Client([], $this->transport(new Spool($this->dir), $down, $callsA));
        $b = new Client([], $this->transport(new Spool($this->dir), $up, $callsB));

        $a->log('info', 'from a');
        $a->flush();
        $b->log('info', 'from b');
        $b->flush();

        $this->assertSame([['from b'], ['from a']], array_column($callsB, 'lines'));
        $this->assertSame(0, (new Spool($this->dir))->count());
    }

    public function test_only_failures_a_retry_can_fix_are_spooled(): void
    {
        foreach ([0 => 1, 429 => 1, 502 => 1, 503 => 1, 504 => 1, 400 => 0, 401 => 0, 413 => 0, 500 => 0] as $code => $spooled) {
            $this->tearDown();
            $calls = [];
            $stats = new Stats;
            $transport = $this->transport(new Spool($this->dir), $code, $calls, $stats);
            $client = new Client([], $transport, $stats);
            $client->log('info', 'x');
            $client->flush();

            $this->assertSame($spooled, $transport->spool()->count(), "status {$code}");
            $this->assertSame(1 - $spooled, $client->stats()['dropped'], "status {$code}");
        }
    }

    public function test_a_full_spool_drops_the_new_batch(): void
    {
        $status = 0;
        $calls = [];
        $stats = new Stats;
        // One envelope is ~125 bytes: room for one batch, not two.
        $transport = $this->transport(new Spool($this->dir, 200), $status, $calls, $stats);
        $client = new Client([], $transport, $stats);

        $client->log('info', 'first');
        $client->flush();
        $this->setPrivate($transport, 'retryAt', 0.0);
        $client->log('info', 'second');
        $client->flush();

        $this->assertSame(1, $transport->spool()->count());
        $this->assertSame(1, $client->stats()['dropped']);
    }

    public function test_expired_batches_are_deleted_unsent_and_counted(): void
    {
        $spool = new Spool($this->dir, 1024 * 1024, 60);
        $this->assertTrue($spool->put(["{\"message\":\"old\"}\n", "{\"message\":\"old2\"}\n"], 'aa'));
        $file = glob($this->dir.'/*.ndjson')[0];
        rename($file, $this->dir.'/'.sprintf('%016d', (int) ((microtime(true) - 120) * 1_000_000)).'-aa.ndjson');

        $status = 202;
        $calls = [];
        $stats = new Stats;
        $client = new Client([], $this->transport($spool, $status, $calls, $stats), $stats);
        $client->log('info', 'now');
        $client->flush();

        $this->assertCount(1, $calls, 'the expired batch is not sent');
        $this->assertSame(0, $spool->count());
        $this->assertSame(2, $client->stats()['dropped']);
    }

    public function test_a_resend_that_fails_again_is_put_back(): void
    {
        $spool = new Spool($this->dir);
        $spool->put(["{\"message\":\"waiting\"}\n"], 'bb');

        $status = 202;
        $calls = [];
        $sender = function (string $url, array $headers, string $body) use (&$calls) {
            $calls[] = $body;

            return ['status' => count($calls) === 1 ? 202 : 503, 'retryable' => false];
        };
        $transport = new HttpTransport('http://x', 't', 1000, new Stats, 500, 1000, $sender, $spool);
        $client = new Client([], $transport);
        $client->log('info', 'now');
        $client->flush();

        $this->assertCount(2, $calls);
        $this->assertSame(1, $spool->count());
        $this->assertCount(1, glob($this->dir.'/*-bb.ndjson'), 'back under its original name');
    }

    public function test_a_batch_taken_by_a_dead_process_is_taken_again(): void
    {
        $spool = new Spool($this->dir);
        $spool->put(["{\"message\":\"orphan\"}\n"], 'cc');
        $expired = 0;
        [$taken] = $spool->claim($expired);

        $this->assertNull($spool->claim($expired), 'being sent by its owner');

        $stale = preg_replace('/\.\d{13}-/', '.'.sprintf('%013d', (int) ((microtime(true) - Spool::CLAIM_TIMEOUT - 1) * 1000)).'-', $taken);
        rename($taken, $stale);
        $claim = $spool->claim($expired);

        $this->assertNotNull($claim);
        $this->assertSame('cc', $claim[1]);
        $this->assertStringContainsString('orphan', $claim[2]);
    }

    public function test_every_ingest_and_token_has_its_own_directory(): void
    {
        $a = Spool::forIngest($this->dir, 'https://ingest.example.com', 'token-a', 1024, 60);
        $b = Spool::forIngest($this->dir, 'https://ingest.example.com', 'token-b', 1024, 60);

        $this->assertNotSame($a->dir(), $b->dir());
        $this->assertStringStartsWith($this->dir.'/', $a->dir());
        $this->assertStringStartsWith(sys_get_temp_dir(), Spool::forIngest(null, 'x', 't', 1, 1)->dir());

        @rmdir($a->dir());
        @rmdir($b->dir());
    }

    public function test_nothing_touches_the_disk_until_a_batch_fails(): void
    {
        $status = 202;
        $calls = [];
        $client = new Client([], $this->transport(new Spool($this->dir), $status, $calls));
        $client->log('info', 'fine');
        $client->flush();

        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_an_unwritable_spool_drops_and_never_throws(): void
    {
        touch($this->dir); // a file where the directory should be
        $status = 0;
        $calls = [];
        $stats = new Stats;
        $client = new Client([], $this->transport(new Spool($this->dir.'/sub'), $status, $calls, $stats), $stats);
        $client->log('info', 'x');
        $client->flush();

        $this->assertSame(1, $client->stats()['dropped']);
        unlink($this->dir);
    }

    public function test_options_build_a_spool_for_the_http_transport(): void
    {
        $on = new Client(['transport' => 'http', 'endpoint' => 'http://x', 'token' => 't', 'spool_path' => $this->dir]);
        $off = new Client(['transport' => 'http', 'endpoint' => 'http://x', 'token' => 't', 'spool' => 'false']);
        $zero = new Client(['transport' => 'http', 'endpoint' => 'http://x', 'token' => 't', 'spool_max_mb' => 0]);

        $this->assertStringStartsWith($this->dir.'/', $on->transport()->spool()->dir());
        $this->assertNull($off->transport()->spool());
        $this->assertNull($zero->transport()->spool());

        $o = Client::normalizeOptions([]);
        $this->assertTrue($o['spool']);
        $this->assertSame(20 * 1024 * 1024, $o['spool_max_bytes']);
        $this->assertSame(3600, $o['spool_max_age_seconds']);
        $this->assertSame(1000, $o['http_timeout_ms']);
        $this->assertSame(5000, $o['http_console_timeout_ms']);
        $this->assertSame(500, Client::normalizeOptions(['http_connect_timeout_ms' => '0'])['http_connect_timeout_ms']);
    }

    private function setPrivate(object $object, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($object, $name);
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true); // a no-op since 8.1, deprecated in 8.5
        }
        $property->setValue($object, $value);
    }
}
