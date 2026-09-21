<?php

namespace MonitorTrack\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use MonitorTrack\Facades\MonitorTrack;
use MonitorTrack\Stats;
use MonitorTrack\Support\OctaneFlusher;
use MonitorTrack\Tests\TestCase;
use MonitorTrack\Transport\HttpTransport;

/**
 * The app as an Octane worker sees it: LARAVEL_OCTANE is set, the worker is
 * not a console process, and every request runs in a clone of the booted
 * application (Laravel\Octane\Worker::handle), which is the current
 * container while the request runs.
 */
class OctaneTest extends TestCase
{
    /** @var list<list<array<string, mixed>>> decoded lines of each POST */
    private array $posts = [];

    private HttpTransport $http;

    protected function setUp(): void
    {
        // What Octane's server process and bin/bootstrap.php set.
        $_SERVER['LARAVEL_OCTANE'] = '1';
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';

        parent::setUp();

        $this->http = new HttpTransport('http://ingest.test', 'tok', 1000, new Stats, 500, 1000,
            function (string $url, array $headers, string $body) {
                $lines = explode("\n", rtrim((string) gzdecode($body), "\n"));
                $this->posts[] = array_map(fn ($line) => json_decode($line, true), $lines);

                return ['status' => 202, 'retryable' => false];
            });
        $this->client()->setTransport($this->http);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_SERVER['LARAVEL_OCTANE'], $_ENV['APP_RUNNING_IN_CONSOLE']);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/orders/{order}', function (Request $request, string $order) {
            self::login($request);
            MonitorTrack::log('info', 'order viewed', ['order' => $order]);

            return 'ok';
        });
        $router->post('/payments/{payment}', function (Request $request) {
            self::login($request);

            throw new \RuntimeException('declined for user '.$request->header('X-User-Id'));
        });
    }

    private static function login(Request $request): void
    {
        auth()->setUser(new GenericUser(['id' => (int) $request->header('X-User-Id')]));
    }

    /**
     * One request the way Octane serves it: clone, switch the current
     * application, the RequestReceived listeners that matter here, handle,
     * terminate, then back to the booted application.
     */
    private function octaneRequest(string $method, string $uri, string $requestId, int $userId): int
    {
        $base = $this->app;
        $sandbox = clone $base;
        self::setCurrent($sandbox);

        try {
            $request = Request::create($uri, $method, [], [], [], [
                'HTTP_X_REQUEST_ID' => $requestId,
                'HTTP_X_USER_ID' => (string) $userId,
                'HTTP_ACCEPT' => 'application/json',
            ]);

            $kernel = $sandbox->make(HttpKernel::class);
            $kernel->setApplication($sandbox);
            $sandbox['router']->setContainer($sandbox);
            $sandbox->instance('config', clone $sandbox['config']);
            if ($sandbox->resolved('auth')) {
                $sandbox['auth']->setApplication($sandbox);
                $sandbox['auth']->forgetGuards();
            }
            $base->instance('request', $request);
            $sandbox->instance('request', $request);
            $sandbox['events']->dispatch('Laravel\Octane\Events\RequestReceived', [$base, $sandbox, $request]);

            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return $response->getStatusCode();
        } finally {
            $sandbox->flush();
            self::setCurrent($base);
        }
    }

    private static function setCurrent(Application $app): void
    {
        $app->instance('app', $app);
        $app->instance(Container::class, $app);
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    /**
     * Pretends the last send was $seconds earlier.
     */
    private function age(float $seconds): void
    {
        $property = new \ReflectionProperty(HttpTransport::class, 'lastFlush');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true); // a no-op since 8.1, deprecated in 8.5
        }
        $property->setValue($this->http, $property->getValue($this->http) - $seconds);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sent(?string $type = null): array
    {
        $events = array_merge([], ...$this->posts);

        return array_values(array_filter($events, fn ($e) => $type === null || $e['type'] === $type));
    }

    public function test_the_worker_is_detected(): void
    {
        $this->assertTrue(OctaneFlusher::running($this->app));

        unset($_SERVER['LARAVEL_OCTANE']);
        $fresh = new Application($this->app->basePath());
        $this->assertFalse(OctaneFlusher::running($fresh), 'no Octane variable, no Octane client');
        $fresh->instance('Laravel\Octane\Contracts\Client', new \stdClass);
        $this->assertTrue(OctaneFlusher::running($fresh), 'booted by an Octane worker');

        $_SERVER['LARAVEL_OCTANE'] = '1';
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'true';
        $this->assertFalse(OctaneFlusher::running(new Application($this->app->basePath())), 'php artisan started from a request');
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
        self::setCurrent($this->app);
    }

    public function test_each_request_carries_its_own_route_user_and_trace(): void
    {
        // A worker's booted application has never resolved the exception
        // handler or the auth manager: each request resolves its own.
        $this->app->forgetInstance(ExceptionHandler::class);
        $this->assertFalse($this->app->resolved('auth'));

        $this->assertSame(200, $this->octaneRequest('GET', '/orders/41', 'req-a', 7));
        $this->assertSame(500, $this->octaneRequest('POST', '/payments/9', 'req-b', 9));
        $this->assertSame(200, $this->octaneRequest('GET', '/orders/42', 'req-c', 11));
        $this->assertSame(500, $this->octaneRequest('POST', '/payments/10', 'req-d', 13));
        $this->http->flush();

        $context = fn (array $e) => [$e['route'] ?? null, $e['user_id'] ?? null, $e['trace_id'] ?? null];

        $logs = $this->sent('log');
        $this->assertSame([
            ['GET /orders/{order}', '7', 'req-a'],
            ['GET /orders/{order}', '11', 'req-c'],
        ], array_map($context, $logs));
        $this->assertSame([['order' => '41'], ['order' => '42']], array_column($logs, 'context'));

        $exceptions = $this->sent('exception');
        $this->assertSame([
            ['POST /payments/{payment}', '9', 'req-b'],
            ['POST /payments/{payment}', '13', 'req-d'],
        ], array_map($context, $exceptions), 'every request\'s handler reports to the SDK');
        $this->assertSame('RuntimeException: declined for user 13', $exceptions[1]['message']);
    }

    public function test_the_buffer_is_sent_when_due_not_per_request(): void
    {
        foreach (range(1, 5) as $i) {
            $this->octaneRequest('GET', "/orders/{$i}", "req-{$i}", $i);
        }
        $this->assertSame([], $this->posts, 'five requests within 2 s: nothing sent yet');
        $this->assertSame(5, $this->http->pending());

        $this->age(2.1);
        $this->octaneRequest('GET', '/orders/6', 'req-6', 6);
        $this->assertCount(1, $this->posts, 'due: one POST with all six requests');
        $this->assertSame(['req-1', 'req-2', 'req-3', 'req-4', 'req-5', 'req-6'], array_column($this->sent('log'), 'trace_id'));

        $this->octaneRequest('GET', '/orders/7', 'req-7', 7);
        $this->assertCount(1, $this->posts);

        // 500 buffered events are due at once.
        foreach (range(1, 498) as $i) {
            MonitorTrack::log('debug', "line {$i}");
        }
        $this->assertSame(499, $this->http->pending());
        $this->octaneRequest('GET', '/orders/8', 'req-8', 8);
        $this->assertCount(2, $this->posts);
        $this->assertCount(500, $this->posts[1]);
        $this->assertSame(0, $this->http->pending());
    }

    public function test_recording_an_event_never_sends_in_a_worker(): void
    {
        $this->age(2.1);
        MonitorTrack::log('info', 'due, but only a request end or the timer sends');

        $this->assertSame([], $this->posts);
    }

    public function test_worker_stopping_sends_everything(): void
    {
        $this->octaneRequest('GET', '/orders/1', 'req-1', 1);
        $this->octaneRequest('POST', '/payments/1', 'req-2', 2);
        $this->assertSame([], $this->posts);

        $this->app['events']->dispatch('Laravel\Octane\Events\WorkerStopping', [$this->app]);

        $this->assertCount(1, $this->posts);
        $this->assertSame(['log', 'exception'], array_column($this->posts[0], 'type'));
        $this->assertSame(0, $this->http->pending());
    }

    public function test_task_and_tick_workers_send_when_due(): void
    {
        MonitorTrack::log('info', 'from a task');

        $this->app['events']->dispatch('Laravel\Octane\Events\TaskTerminated');
        $this->assertSame([], $this->posts, 'not due');

        $this->age(2.1);
        $this->app['events']->dispatch('Laravel\Octane\Events\TickTerminated');
        $this->assertCount(1, $this->posts);
    }
}
