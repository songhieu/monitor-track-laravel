<?php

namespace MonitorTrack\Tests\Unit;

use MonitorTrack\Support\QueryThrottle;
use PHPUnit\Framework\TestCase;

class QueryThrottleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mt-throttle-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/mt-q-*'));
        rmdir($this->dir);
    }

    public function test_one_per_key_per_window(): void
    {
        $throttle = new QueryThrottle(60, $this->dir);

        $this->assertTrue($throttle->allow('n_plus_one|select 1|app/A.php:3'));
        $this->assertFalse($throttle->allow('n_plus_one|select 1|app/A.php:3'));
        $this->assertTrue($throttle->allow('slow|select 1|app/A.php:3'), 'other key');

        // A second PHP process (the next FPM request) sees the same marker.
        $this->assertFalse((new QueryThrottle(60, $this->dir))->allow('n_plus_one|select 1|app/A.php:3'));

        $marker = $throttle->path('n_plus_one|select 1|app/A.php:3');
        $this->assertSame(0666, fileperms($marker) & 0777, 'shared by FPM and CLI users');
        touch($marker, time() - 61);
        $this->assertTrue($throttle->allow('n_plus_one|select 1|app/A.php:3'), 'window passed');
        $this->assertFalse($throttle->allow('n_plus_one|select 1|app/A.php:3'));
    }

    public function test_zero_seconds_never_throttles(): void
    {
        $throttle = new QueryThrottle(0, $this->dir);

        $this->assertTrue($throttle->allow('k'));
        $this->assertTrue($throttle->allow('k'));
        $this->assertSame([], glob($this->dir.'/mt-q-*'));
    }

    public function test_unwritable_directory_falls_back_to_sampling(): void
    {
        $missing = $this->dir.'/missing/dir';

        $this->assertTrue((new QueryThrottle(60, $missing, 1.0))->allow('k'));
        $this->assertFalse((new QueryThrottle(60, $missing, 0.0))->allow('k'));

        $throttle = new QueryThrottle(60, $missing);
        $sent = 0;
        for ($i = 0; $i < 2000; $i++) {
            $sent += $throttle->allow('k') ? 1 : 0;
        }
        $this->assertGreaterThan(0, $sent);
        $this->assertLessThan(100, $sent, 'about 1%');
    }
}
