<?php

namespace MonitorTrack\Tests\Feature;

use Illuminate\Support\Facades\Log;
use MonitorTrack\Facades\MonitorTrack;
use MonitorTrack\Monolog\Handler;
use MonitorTrack\Stats;
use MonitorTrack\Tests\TestCase;
use MonitorTrack\Transport\HttpTransport;
use MonitorTrack\Transport\StreamTransport;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

class ServiceProviderTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/api/v1/payments/{payment}', function () {
            throw new \RuntimeException('gateway down');
        });
        $router->get('/ok', fn () => 'ok');
    }

    public function test_client_is_a_configured_singleton(): void
    {
        $client = $this->client();

        $this->assertSame($client, $this->app->make('monitor-track'));
        $this->assertSame($client, MonitorTrack::getFacadeRoot());
        $this->assertTrue($client->isEnabled());
        $this->assertInstanceOf(StreamTransport::class, $client->transport());
        $this->assertSame('stream php://stderr', $client->transport()->describe());
        $this->assertSame('custom', config('logging.channels.monitor-track.driver'));

        $memory = $this->fake();
        MonitorTrack::log('info', 'hello');
        $event = $memory->events()[0];
        $this->assertSame('billing-api', $event['app']);
        $this->assertSame('testing', $event['env']);
        $this->assertSame('2026.09.21-2', $event['release']);
        $this->assertSame(gethostname(), $event['host']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}[+-]\d\d:\d\d$/', $event['ts']);
    }

    public function test_monitor_track_log_channel_writes_envelopes(): void
    {
        $memory = $this->fake();

        Log::channel('monitor-track')->warning('Payment retry scheduled', ['order_id' => 991, 'token' => 'secret']);
        Log::channel('monitor-track')->critical('Card vault unreachable', ['exception' => new \LogicException('vault')]);

        $events = $memory->events();
        $this->assertCount(2, $events);
        $this->assertSame('log', $events[0]['type']);
        $this->assertSame('warning', $events[0]['level']);
        $this->assertSame(['order_id' => 991, 'token' => '***'], $events[0]['context']);

        $this->assertSame('exception', $events[1]['type']);
        $this->assertSame('critical', $events[1]['level']);
        $this->assertSame('LogicException', $events[1]['exception']['class']);
        $this->assertSame('Card vault unreachable', $events[1]['context']['log_message']);
        $this->assertArrayNotHasKey('exception', $events[1]['context']);
    }

    public function test_monolog_handler_maps_levels_and_never_throws(): void
    {
        // Monolog 3 (Laravel 10+) levels are an enum, Monolog 2 (Laravel 9) ints.
        $monolog3 = Logger::API >= 3;
        $this->assertSame('critical', Handler::level($monolog3 ? Level::Emergency : Logger::EMERGENCY));
        $this->assertSame('notice', Handler::level($monolog3 ? Level::Notice : Logger::NOTICE));
        $this->assertSame('warning', Handler::level($monolog3 ? Level::Warning : Logger::WARNING));

        $memory = $this->fake();
        $handler = new Handler($this->client());
        $handler->pushProcessor(function () {
            throw new \RuntimeException('broken processor');
        });

        $record = $monolog3
            ? new LogRecord(new \DateTimeImmutable, 'app', Level::Info, 'x')
            : ['message' => 'x', 'context' => [], 'level' => Logger::INFO, 'level_name' => 'INFO',
                'channel' => 'app', 'datetime' => new \DateTimeImmutable, 'extra' => []];
        $this->assertFalse($handler->handle($record), 'bubbles, does not throw');
        $this->assertSame([], $memory->lines());
    }

    public function test_reported_exception_carries_route_trace_and_frames_once(): void
    {
        // The default log channel also goes through the SDK handler, so the
        // same exception is seen by reportable() and by the logger: one event.
        config(['logging.default' => 'monitor-track']);
        $memory = $this->fake();

        $this->post('/api/v1/payments/42', [], [
            'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ])->assertStatus(500);

        $exceptions = $memory->events('exception');
        $this->assertCount(1, $exceptions);
        $event = $exceptions[0];
        $this->assertSame('RuntimeException: gateway down', $event['message']);
        $this->assertSame('POST /api/v1/payments/{payment}', $event['route']);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $event['trace_id']);
        $this->assertStringEndsWith('ServiceProviderTest.php', $event['exception']['frames'][0]['file']);
        $this->assertCount(0, $memory->events('log'));
    }

    public function test_request_id_is_the_fallback_trace_id(): void
    {
        $memory = $this->fake();

        $this->post('/api/v1/payments/1', [], ['X-Request-Id' => 'req-123'])->assertStatus(500);

        $this->assertSame('req-123', $memory->events('exception')[0]['trace_id']);
    }

    public function test_dont_report_exceptions_are_not_captured(): void
    {
        $memory = $this->fake();

        $this->get('/missing')->assertStatus(404);

        $this->assertSame([], $memory->events('exception'));
    }

    public function test_user_id_is_added_when_the_user_is_already_loaded(): void
    {
        $memory = $this->fake();

        $user = new \Illuminate\Auth\GenericUser(['id' => 1823, 'name' => 'x']);
        $this->actingAs($user);
        $this->get('/ok')->assertOk();
        $this->client()->log('info', 'after login');

        $this->assertSame('1823', $memory->events('log')[0]['user_id']);
    }

    /**
     * @param  list<int>  $posts  number of lines of each POST
     */
    private function httpTransport(array &$posts): HttpTransport
    {
        $http = new HttpTransport('http://ingest.test', 'tok', 1000, new Stats, 500, 1000,
            function (string $url, array $headers, string $body) use (&$posts) {
                $posts[] = substr_count((string) gzdecode($body), "\n");

                return ['status' => 202, 'retryable' => false];
            });
        $this->client()->setTransport($http);

        return $http;
    }

    public function test_http_buffer_is_sent_after_every_request_outside_octane(): void
    {
        $posts = [];
        $this->httpTransport($posts);

        $this->post('/api/v1/payments/1')->assertStatus(500);
        $this->post('/api/v1/payments/2')->assertStatus(500);

        $this->assertSame([1, 1], $posts, 'FPM: one POST per request, after the response');
    }

    public function test_console_processes_send_when_due_as_events_are_recorded_until_a_worker_loop_starts(): void
    {
        $posts = [];
        $http = $this->httpTransport($posts);
        $age = function () use ($http) {
            $property = new \ReflectionProperty(HttpTransport::class, 'lastFlush');
            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true); // a no-op since 8.1, deprecated in 8.5
            }
            $property->setValue($http, microtime(true) - 2.1);
        };

        $this->client()->log('info', 'a');
        $this->assertSame([], $posts, 'not due');
        $age();
        $this->client()->log('info', 'b');
        $this->assertSame([2], $posts, 'due: sent while the command runs');

        // A queue worker sends between jobs (Looping), never from inside one.
        event(new \Illuminate\Queue\Events\Looping('redis', 'default'));
        $age();
        $this->client()->log('info', 'c');
        $this->assertSame([2], $posts);
    }

    public function test_horizon_supervisor_loops_send_when_due(): void
    {
        $posts = [];
        $http = $this->httpTransport($posts);
        $this->client()->log('warning', 'redis reconnected');

        event('Laravel\Horizon\Events\SupervisorLooped');
        $this->assertSame([], $posts, 'not due');

        $property = new \ReflectionProperty(HttpTransport::class, 'lastFlush');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true); // a no-op since 8.1, deprecated in 8.5
        }
        $property->setValue($http, microtime(true) - 2.1);
        event('Laravel\Horizon\Events\MasterSupervisorLooped');
        $this->assertSame([1], $posts);
    }

    public function test_mt_test_command_emits_one_of_each_type(): void
    {
        $memory = $this->fake();

        $this->artisan('mt:test')->assertExitCode(0);

        $types = array_count_values(array_column($memory->events(), 'type'));
        $this->assertSame(['log' => 1, 'exception' => 1, 'job' => 2, 'cron' => 2, 'heartbeat' => 1], $types);

        $cron = $memory->events('cron')[0]['cron'];
        $this->assertArrayNotHasKey('expr', $cron, 'mt:test never registers a real schedule');
        $this->assertSame('***', $memory->events('log')[0]['context']['password']);
    }
}
