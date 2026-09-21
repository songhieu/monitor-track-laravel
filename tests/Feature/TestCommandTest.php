<?php

namespace MonitorTrack\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use MonitorTrack\Console\TestCommand;
use MonitorTrack\Tests\TestCase;

class TestCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/mt-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        // Default stream transport, but into a file instead of the test's stderr.
        $this->client()->streamTo($this->dir.'/stderr');
        $this->withoutMockingConsoleOutput();
    }

    protected function tearDown(): void
    {
        TestCommand::$podLog = '/proc/1/fd/2';
        putenv('KUBERNETES_SERVICE_HOST');
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function envelopes(string $file): array
    {
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

        return array_map(fn ($l) => json_decode($l, true), $lines ?: []);
    }

    public function test_pod_log_writes_to_the_container_stderr(): void
    {
        TestCommand::$podLog = $this->dir.'/pid1-stderr';
        touch(TestCommand::$podLog);

        $this->assertSame(0, Artisan::call('mt:test', ['--pod-log' => true]));

        $events = $this->envelopes(TestCommand::$podLog);
        $this->assertCount(7, $events);
        $this->assertSame(1, $events[0]['_mt']);
        $this->assertSame([], $this->envelopes($this->dir.'/stderr'), 'nothing on the command\'s own stderr');
        $this->assertStringContainsString('(pod log)', Artisan::output());
    }

    public function test_pod_log_fails_clearly_when_not_writable(): void
    {
        TestCommand::$podLog = $this->dir.'/missing/dir/fd';

        $this->assertSame(1, Artisan::call('mt:test', ['--pod-log' => true]));
        $this->assertStringContainsString('Cannot write to', Artisan::output());
        $this->assertSame([], $this->envelopes($this->dir.'/stderr'));
    }

    public function test_pod_log_is_ignored_for_other_transports(): void
    {
        $memory = $this->fake();

        $this->assertSame(0, Artisan::call('mt:test', ['--pod-log' => true]));
        $this->assertStringContainsString('only applies to MT_TRANSPORT=stream', Artisan::output());
        $this->assertCount(7, $memory->events());
    }

    public function test_hints_at_pod_log_inside_kubernetes(): void
    {
        putenv('KUBERNETES_SERVICE_HOST=10.96.0.1');

        $this->assertSame(0, Artisan::call('mt:test'));
        $this->assertCount(7, $this->envelopes($this->dir.'/stderr'));
        if (getmypid() === 1) {
            // This process is the container's main process (e.g. `docker run
            // … phpunit`): its stderr is the pod log, so no hint.
            $this->assertStringNotContainsString('--pod-log', Artisan::output());
        } else {
            $this->assertStringContainsString('--pod-log', Artisan::output());
        }
    }

    public function test_no_hint_outside_kubernetes(): void
    {
        putenv('KUBERNETES_SERVICE_HOST');

        $this->assertSame(0, Artisan::call('mt:test'));
        $this->assertStringNotContainsString('--pod-log', Artisan::output());
    }
}
