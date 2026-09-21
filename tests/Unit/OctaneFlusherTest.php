<?php

namespace MonitorTrack\Tests\Unit;

use MonitorTrack\Client;
use MonitorTrack\Stats;
use MonitorTrack\Support\OctaneFlusher;
use MonitorTrack\Transport\HttpTransport;
use PHPUnit\Framework\TestCase;

class OctaneFlusherTest extends TestCase
{
    private int $posted = 0;

    /** @var array<int, callable> pending fake Swoole timers by id */
    private array $timers = [];

    /** @var list<int> */
    private array $cleared = [];

    private int $lastTimer = 0;

    private HttpTransport $http;

    private Client $client;

    protected function setUp(): void
    {
        $this->http = new HttpTransport('http://x', 't', 1000, new Stats, 500, 1000,
            function (string $url, array $headers, string $body) {
                $this->posted += substr_count((string) gzdecode($body), "\n");

                return ['status' => 202, 'retryable' => false];
            });
        $this->client = new Client([], $this->http);
    }

    private function flusher(): OctaneFlusher
    {
        return new OctaneFlusher(
            $this->client,
            function (int $ms, callable $fn): int {
                $this->assertSame(2050, $ms);
                $this->timers[++$this->lastTimer] = $fn;

                return $this->lastTimer;
            },
            function (int $id): void {
                unset($this->timers[$id]);
                $this->cleared[] = $id;
            },
        );
    }

    private function fire(): void
    {
        $id = array_key_first($this->timers);
        $fn = $this->timers[$id];
        unset($this->timers[$id]);
        $fn();
    }

    private function age(float $seconds): void
    {
        $property = new \ReflectionProperty(HttpTransport::class, 'lastFlush');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true); // a no-op since 8.1, deprecated in 8.5
        }
        $property->setValue($this->http, $property->getValue($this->http) - $seconds);
    }

    public function test_a_timer_sends_what_no_later_request_would(): void
    {
        $flusher = $this->flusher();

        $flusher->requestTerminated();
        $this->assertSame([], $this->timers, 'nothing buffered, no timer');

        $this->client->log('info', 'a');
        $flusher->requestTerminated();
        $this->client->log('info', 'b');
        $flusher->requestTerminated();
        $this->assertCount(1, $this->timers, 'one timer at a time');
        $this->assertSame(0, $this->posted);

        // The worker is idle; the timer fires 2 s later.
        $this->age(2.1);
        $this->fire();
        $this->assertSame(2, $this->posted);
        $this->assertSame([], $this->timers, 'nothing left, not re-armed');
        $this->assertFalse($flusher->timerPending());
    }

    public function test_the_timer_is_re_armed_while_events_wait(): void
    {
        $flusher = $this->flusher();
        $this->client->log('info', 'a');
        $flusher->requestTerminated();

        // A request flushed in between, then buffered more: not due yet.
        $this->age(2.1);
        $this->client->flushIfDue();
        $this->client->log('info', 'b');
        $this->fire();
        $this->assertSame(1, $this->posted);
        $this->assertCount(1, $this->timers, 'b still waits');

        $this->age(2.1);
        $this->fire();
        $this->assertSame(2, $this->posted);
    }

    public function test_worker_stopping_clears_the_timer_and_sends_everything(): void
    {
        $flusher = $this->flusher();
        $this->client->log('info', 'a');
        $flusher->requestTerminated();
        $this->assertTrue($flusher->timerPending());

        $flusher->workerStopping();

        $this->assertSame(1, $this->posted);
        $this->assertSame([1], $this->cleared);
        $this->assertFalse($flusher->timerPending());
    }

    public function test_without_a_timer_requests_send_when_due(): void
    {
        $flusher = new OctaneFlusher($this->client);
        $this->client->log('info', 'a');
        $flusher->requestTerminated();
        $this->assertSame(0, $this->posted);

        $this->age(2.1);
        $flusher->operationTerminated();
        $this->assertSame(1, $this->posted);
    }

    public function test_a_failing_timer_never_throws(): void
    {
        $flusher = new OctaneFlusher($this->client, function () {
            throw new \RuntimeException('no event loop');
        });
        $this->client->log('info', 'a');
        $flusher->requestTerminated();

        $this->assertFalse($flusher->timerPending());
        $this->assertSame(1, $this->http->pending());
    }

    public function test_outside_a_swoole_worker_there_is_no_timer(): void
    {
        $app = new class
        {
            public function bound(string $abstract): bool
            {
                return false;
            }
        };

        $flusher = OctaneFlusher::forWorker($this->client, $app);
        $this->client->log('info', 'a');
        $flusher->requestTerminated();
        $this->assertFalse($flusher->timerPending());
    }
}
