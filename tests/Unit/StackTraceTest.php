<?php

namespace MonitorTrack\Tests\Unit;

use MonitorTrack\Client;
use MonitorTrack\Support\StackTrace;
use MonitorTrack\Tests\Fixtures\App\Http\PaymentController;
use MonitorTrack\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

class StackTraceTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = realpath(__DIR__.'/../Fixtures');
        require_once $this->base.'/app/Services/PaymentGateway.php';
        require_once $this->base.'/app/Http/PaymentController.php';
        require_once $this->base.'/vendor/acme/http/HttpClient.php';
    }

    private function thrown(): \Throwable
    {
        try {
            (new PaymentController)->store();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('fixture did not throw');
    }

    public function test_frames_are_innermost_first_relative_and_flag_vendor(): void
    {
        $frames = StackTrace::frames($this->thrown(), $this->base);

        $this->assertSame([
            'file' => 'app/Services/PaymentGateway.php',
            'line' => 9,
            'func' => 'MonitorTrack\\Tests\\Fixtures\\App\\Services\\PaymentGateway->post',
            'in_app' => true,
        ], $frames[0]);

        $this->assertSame('app/Http/PaymentController.php', $frames[1]['file']);
        $this->assertSame(13, $frames[1]['line']);
        $this->assertStringContainsString('{closure', $frames[1]['func']);
        $this->assertTrue($frames[1]['in_app']);

        $this->assertSame([
            'file' => 'vendor/acme/http/HttpClient.php',
            'line' => 9,
            'func' => 'MonitorTrack\\Tests\\Fixtures\\Vendor\\HttpClient->send',
            'in_app' => false,
        ], $frames[2]);

        $this->assertSame([
            'file' => 'app/Http/PaymentController.php',
            'line' => 16,
            'func' => 'MonitorTrack\\Tests\\Fixtures\\App\\Http\\PaymentController->store',
            'in_app' => true,
        ], $frames[3]);

        // Callers outside the base path keep absolute paths and are not in-app.
        $this->assertStringEndsWith('tests/Unit/StackTraceTest.php', $frames[4]['file']);
        $this->assertStringStartsWith('/', $frames[4]['file']);
        $this->assertFalse($frames[4]['in_app']);
        $this->assertSame(__CLASS__.'->thrown', $frames[4]['func']);
    }

    public function test_frames_are_capped(): void
    {
        $recurse = function (int $n) use (&$recurse) {
            if ($n === 0) {
                throw new \LogicException('deep');
            }
            $recurse($n - 1);
        };

        try {
            $recurse(80);
        } catch (\LogicException $e) {
            $this->assertCount(50, StackTrace::frames($e, $this->base));
            $this->assertCount(20, StackTrace::frames($e, $this->base, 20));
        }
    }

    public function test_capture_exception_builds_exception_event_once(): void
    {
        $memory = new MemoryTransport;
        $client = new Client(['app' => 'billing-api', 'base_path' => $this->base], $memory);
        $e = $this->thrown();

        $client->captureException($e, ['order_id' => 991, 'token' => 'abc']);
        $client->captureException($e); // same Throwable: ignored

        $this->assertCount(1, $memory->lines());
        $event = $memory->events()[0];
        $this->assertSame('exception', $event['type']);
        $this->assertSame('error', $event['level']);
        $this->assertSame('RuntimeException: card declined', $event['message']);
        $this->assertSame(['order_id' => 991, 'token' => '***'], $event['context']);
        $this->assertSame(['class', 'message', 'frames'], array_keys($event['exception']));
        $this->assertSame('RuntimeException', $event['exception']['class']);
        $this->assertSame('card declined', $event['exception']['message']);
        $this->assertSame('app/Services/PaymentGateway.php', $event['exception']['frames'][0]['file']);
        $this->assertTrue($client->hasCaptured($e));
    }

    public function test_sdk_frames_are_dropped(): void
    {
        $e = null;
        $client = new Client(['app' => 'x', 'base_path' => $this->base], new MemoryTransport);

        // trackJob runs the callable from inside the SDK: its frame must not
        // show up in the trace.
        try {
            $client->trackJob('q', 'J', function () {
                throw new \RuntimeException('inside');
            });
        } catch (\RuntimeException $e) {
        }

        $funcs = array_column(StackTrace::frames($e, $this->base), 'func');
        foreach ($funcs as $func) {
            $this->assertStringStartsNotWith('MonitorTrack\\Client', $func);
        }
    }
}
