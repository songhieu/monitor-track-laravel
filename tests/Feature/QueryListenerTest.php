<?php

namespace MonitorTrack\Tests\Feature;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MonitorTrack\Listeners\QueryListener;
use MonitorTrack\MonitorTrackServiceProvider;
use MonitorTrack\Support\QueryThrottle;
use MonitorTrack\Tests\Fixtures\App\Console\BuildReports;
use MonitorTrack\Tests\Fixtures\App\Http\OrderController;
use MonitorTrack\Tests\Fixtures\App\Jobs\SyncStock;
use MonitorTrack\Tests\Fixtures\App\Services\CustomerLookup;
use MonitorTrack\Tests\Fixtures\App\Services\ReportRepository;
use MonitorTrack\Tests\Fixtures\TestQueueJob;
use MonitorTrack\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class QueryListenerTest extends TestCase
{
    private const LAZY_CUSTOMER = 'select * from "customers" where "customers"."id" = ? limit ?';

    private const CUSTOMER_NAME = 'select "name" from "customers" where "id" = ? limit ?';

    private string $base;

    protected function setUp(): void
    {
        $this->base = (string) realpath(__DIR__.'/../Fixtures');
        foreach (['Models/Customer', 'Models/Order', 'Http/OrderController', 'Services/CustomerLookup',
            'Services/ReportRepository', 'Jobs/SyncStock', 'Console/BuildReports'] as $file) {
            require_once $this->base.'/app/'.$file.'.php';
        }

        parent::setUp();

        // Frames are relative to the fixture "app", so its files are in_app.
        $this->client()->setBasePath($this->base);

        Schema::create('customers', function ($table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('orders', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('customer_id');
        });

        $customers = $orders = [];
        foreach (range(1, 12) as $i) {
            $customers[] = ['id' => $i, 'name' => "Customer {$i}"];
            $orders[] = ['id' => $i, 'customer_id' => $i];
        }
        DB::table('customers')->insert($customers);
        DB::table('orders')->insert($orders);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineRoutes($router): void
    {
        $router->get('/shops/{shop}/orders', [OrderController::class, 'index']);
        $router->get('/shops/{shop}/orders/eager', [OrderController::class, 'eager']);
        $router->get('/reports', function () {
            $reports = new ReportRepository;
            $reports->totals(DB::connection(), 812.4);
            $reports->totals(DB::connection(), 640.0);
            $reports->totals(DB::connection(), 12.0);

            return 'ok';
        });
    }

    private function lineOf(string $file, string $code): int
    {
        foreach (file($this->base.'/'.$file) as $i => $line) {
            if (str_contains($line, $code)) {
                return $i + 1;
            }
        }
        $this->fail("{$code} not found in {$file}");
    }

    public function test_n_plus_one_in_a_request_is_reported_once_at_its_call_site(): void
    {
        $memory = $this->fake();

        $this->get('/shops/main/orders')->assertOk();

        $events = $memory->events('query');
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('warning', $event['level']);
        $this->assertSame('N+1 query: 12× '.self::LAZY_CUSTOMER, $event['message']);
        $this->assertSame('GET /shops/{shop}/orders', $event['route']);

        $query = $event['query'];
        $this->assertSame(
            ['kind', 'sql', 'connection', 'count', 'total_ms', 'max_ms', 'threshold', 'scope', 'scope_name', 'frames'],
            array_keys($query),
        );
        $this->assertSame('n_plus_one', $query['kind']);
        $this->assertSame(self::LAZY_CUSTOMER, $query['sql']);
        $this->assertSame('testing', $query['connection']);
        $this->assertSame(12, $query['count']);
        $this->assertIsFloat($query['total_ms']);
        $this->assertIsFloat($query['max_ms']);
        $this->assertGreaterThanOrEqual($query['max_ms'], $query['total_ms']);
        $this->assertSame(10, $query['threshold']);
        $this->assertSame('request', $query['scope']);
        $this->assertSame('GET /shops/{shop}/orders', $query['scope_name']);

        // The first frame is the app line that triggered the query, not
        // Eloquent, the event dispatcher or the SDK.
        $this->assertSame([
            'file' => 'app/Http/OrderController.php',
            'line' => $this->lineOf('app/Http/OrderController.php', '$order->customer->name'),
            'func' => OrderController::class.'->index',
            'in_app' => true,
        ], $query['frames'][0]);
        $this->assertLessThanOrEqual(20, count($query['frames']));
        foreach ($query['frames'] as $frame) {
            $this->assertStringStartsNotWith('MonitorTrack\\Listeners', $frame['func']);
            $this->assertStringStartsNotWith('MonitorTrack\\Client', $frame['func']);
        }
    }

    public function test_below_the_threshold_nothing_is_sent(): void
    {
        $memory = $this->fake();

        $this->get('/shops/main/orders/eager')->assertOk(); // two queries
        dispatch(new SyncStock(range(1, 9)));
        $this->app->terminate();

        $this->assertSame([], $memory->events('query'));
    }

    public function test_n_plus_one_in_a_queued_job(): void
    {
        $memory = $this->fake();

        dispatch(new SyncStock(range(1, 12)));

        $events = $memory->events('query');
        $this->assertCount(1, $events);
        $query = $events[0]['query'];
        $this->assertSame(self::CUSTOMER_NAME, $query['sql']);
        $this->assertSame(12, $query['count']);
        $this->assertSame('job', $query['scope']);
        $this->assertSame(SyncStock::class, $query['scope_name']);
        $this->assertSame('app/Services/CustomerLookup.php', $query['frames'][0]['file']);
        $this->assertSame($this->lineOf('app/Services/CustomerLookup.php', "DB::table('customers')"), $query['frames'][0]['line']);
        $this->assertSame(CustomerLookup::class.'->names', $query['frames'][0]['func']);
        $this->assertSame('app/Jobs/SyncStock.php', $query['frames'][1]['file']);
        $this->assertSame(SyncStock::class.'->handle', $query['frames'][1]['func']);
    }

    public function test_n_plus_one_in_an_artisan_command(): void
    {
        $kernel = $this->app->make(Kernel::class);
        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            // Laravel 11+ only fires CommandStarting / CommandFinished outside
            // unit tests.
            $kernel->rerouteSymfonyCommandEvents();
            $kernel->setArtisan(null);
        }
        $kernel->registerCommand(new BuildReports);
        $memory = $this->fake();

        $this->artisan('reports:build')->assertExitCode(0);

        $events = $memory->events('query');
        $this->assertCount(1, $events);
        $this->assertSame('command', $events[0]['query']['scope']);
        $this->assertSame('artisan reports:build', $events[0]['query']['scope_name']);
        $this->assertSame(12, $events[0]['query']['count']);
        $this->assertSame('app/Services/CustomerLookup.php', $events[0]['query']['frames'][0]['file']);
    }

    public function test_n_plus_one_in_a_scheduled_callback(): void
    {
        $memory = $this->fake();
        $task = new CallbackEvent($this->createStub(EventMutex::class), fn () => null);
        $task->description = 'stock:sync';

        event(new ScheduledTaskStarting($task));
        (new CustomerLookup)->names(range(1, 10));
        event(new ScheduledTaskFinished($task, 0.1));

        $query = $memory->events('query')[0]['query'];
        $this->assertSame(['schedule', 'stock:sync', 10], [$query['scope'], $query['scope_name'], $query['count']]);
    }

    public function test_worker_state_is_per_job_and_polling_is_not_counted(): void
    {
        $memory = $this->fake();
        $lookup = new CustomerLookup;

        event(new Looping('redis', 'default'));
        $lookup->names(range(1, 12)); // between jobs: the worker's own queries

        foreach (['job-a', 'job-b'] as $uuid) {
            $job = TestQueueJob::make($uuid);
            event(new JobProcessing('redis', $job));
            $lookup->names(range(1, 6));
            event(new JobProcessed('redis', $job));
        }
        $this->assertSame([], $memory->events('query'), '6 + 6 in two jobs is not 12 in one');

        $job = TestQueueJob::make('job-c');
        event(new JobProcessing('redis', $job));
        $lookup->names(range(1, 11));
        event(new JobProcessed('redis', $job));
        event(new JobProcessed('redis', $job)); // a second closing event is ignored

        $events = $memory->events('query');
        $this->assertCount(1, $events);
        $this->assertSame(['job', 'App\\Jobs\\SendInvoiceEmail', 11], [
            $events[0]['query']['scope'], $events[0]['query']['scope_name'], $events[0]['query']['count'],
        ]);

        $lookup->names(range(1, 12));
        $this->app->terminate();
        $this->assertCount(1, $memory->events('query'), 'worker exit: nothing outside jobs');
    }

    public function test_long_running_commands_are_not_scopes(): void
    {
        $memory = $this->fake();

        event(new CommandStarting('horizon:work', new ArrayInput([]), new NullOutput));
        (new CustomerLookup)->names(range(1, 12));
        $this->app->terminate();

        $this->assertSame([], $memory->events('query'));
    }

    public function test_queries_outside_any_scope_are_evaluated_on_terminating(): void
    {
        $memory = $this->fake();

        (new CustomerLookup)->names(range(1, 10));
        $this->assertSame([], $memory->events('query'), 'sent when the scope ends');

        $this->app->terminate();

        $query = $memory->events('query')[0]['query'];
        $this->assertSame('command', $query['scope']);
        $this->assertSame(10, $query['count']);

        $this->app->terminate();
        $this->assertCount(1, $memory->events('query'), 'counters start over');
    }

    public function test_slow_queries_are_aggregated_per_statement_and_call_site(): void
    {
        $memory = $this->fake();

        $this->get('/reports')->assertOk();

        $events = $memory->events('query');
        $this->assertCount(1, $events);
        $this->assertSame('Slow query (812ms): select sum(total) from orders where placed_at > ?', $events[0]['message']);
        $query = $events[0]['query'];
        $this->assertSame('slow', $query['kind']);
        $this->assertSame('select sum(total) from orders where placed_at > ?', $query['sql']);
        $this->assertSame(2, $query['count'], 'the 12 ms run is not slow');
        $this->assertSame(1452.4, $query['total_ms']);
        $this->assertSame(812.4, $query['max_ms']);
        $this->assertSame(500, $query['threshold']);
        $this->assertSame(['request', 'GET /reports'], [$query['scope'], $query['scope_name']]);
        $this->assertSame('app/Services/ReportRepository.php', $query['frames'][0]['file']);
        $this->assertSame(ReportRepository::class.'->totals', $query['frames'][0]['func']);
    }

    public function test_throttle_sends_one_event_per_window(): void
    {
        $dir = sys_get_temp_dir().'/mt-throttle-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $this->app->make(QueryListener::class)->setThrottle(new QueryThrottle(60, $dir));
        $memory = $this->fake();

        try {
            $this->get('/shops/main/orders')->assertOk();
            $this->get('/shops/main/orders')->assertOk();
            $this->assertCount(1, $memory->events('query'), 'second request within the window');

            $markers = glob($dir.'/mt-q-*');
            $this->assertCount(1, $markers);
            foreach ($markers as $marker) {
                touch($marker, time() - 61);
            }

            $this->get('/shops/main/orders')->assertOk();
            $this->assertCount(2, $memory->events('query'), 'window passed');
        } finally {
            array_map('unlink', glob($dir.'/mt-q-*'));
            rmdir($dir);
        }
    }

    public function test_listener_is_registered_only_when_a_check_is_on(): void
    {
        $this->assertTrue($this->app->bound(QueryListener::class));

        $this->assertFalse($this->listensAfterBoot(['monitor-track.capture.queries' => false]));
        $this->assertFalse($this->listensAfterBoot(['monitor-track.capture.queries' => true, 'monitor-track.slow_query_ms' => 0, 'monitor-track.n_plus_one' => 0]));
        $this->assertTrue($this->listensAfterBoot(['monitor-track.slow_query_ms' => 'off', 'monitor-track.n_plus_one' => 10]));
    }

    /**
     * Boots a second copy of the provider on a fresh dispatcher.
     *
     * @param  array<string, mixed>  $config
     */
    private function listensAfterBoot(array $config): bool
    {
        config($config);
        $events = new Dispatcher($this->app);
        $this->app->instance('events', $events);
        $provider = new MonitorTrackServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        return $events->hasListeners(QueryExecuted::class);
    }
}
