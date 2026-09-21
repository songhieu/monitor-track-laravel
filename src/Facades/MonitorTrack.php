<?php

namespace MonitorTrack\Facades;

use Illuminate\Support\Facades\Facade;
use MonitorTrack\Client;

/**
 * @method static void log(string $level, string $message, array $context = [])
 * @method static void captureException(\Throwable $e, array $context = [], ?string $level = null)
 * @method static void job(string $status, array $job, array $context = [])
 * @method static void cron(string $phase, array $cron, array $context = [])
 * @method static void heartbeat(array $heartbeat)
 * @method static mixed trackJob(string $queue, string $class, callable $fn, array $options = [])
 * @method static mixed trackCron(string $name, ?string $expr, callable $fn, ?string $tz = null)
 * @method static void record(string $type, string $level, string $message, array $fields = [])
 * @method static void flush(float $timeout = 2.0)
 * @method static array stats()
 * @method static bool isEnabled()
 * @method static \MonitorTrack\Transport\MemoryTransport fake()
 * @method static \MonitorTrack\Transport\Transport transport()
 *
 * @see Client
 */
class MonitorTrack extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
