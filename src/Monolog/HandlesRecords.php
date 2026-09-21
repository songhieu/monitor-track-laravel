<?php

namespace MonitorTrack\Monolog;

use Illuminate\Container\Container;
use MonitorTrack\Client;

/**
 * The part of the Monolog handler that doesn't depend on the Monolog major
 * version: Monolog 3 (Laravel 10+) passes LogRecord objects, Monolog 2
 * (Laravel 9) passes arrays.
 *
 * @internal
 */
trait HandlesRecords
{
    private ?Client $client;

    /**
     * @param  array<array-key, mixed>  $context
     * @param  array<array-key, mixed>  $extra
     */
    private function emit(string $message, array $context, array $extra, int $level): void
    {
        $client = $this->client();
        if ($client === null || ! $client->isEnabled()) {
            return;
        }

        if ($extra !== []) {
            $context['extra'] = $extra;
        }

        $name = self::levelName($level);
        $exception = $context['exception'] ?? null;

        if ($exception instanceof \Throwable) {
            unset($context['exception']);
            if ($message !== '' && $message !== $exception->getMessage()) {
                $context['log_message'] = $message;
            }
            $client->captureException($exception, $context, $name === 'critical' ? 'critical' : 'error');
        } else {
            $client->log($name, $message, $context);
        }
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    private function client(): ?Client
    {
        if ($this->client === null) {
            try {
                $container = Container::getInstance();
                if ($container->bound(Client::class)) {
                    $this->client = $container->make(Client::class);
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->client;
    }

    /**
     * Monolog's numeric levels (the same in 2 and 3) to wire levels.
     */
    private static function levelName(int $level): string
    {
        return match (true) {
            $level < 200 => 'debug',
            $level < 250 => 'info',
            $level < 300 => 'notice',
            $level < 400 => 'warning',
            $level < 500 => 'error',
            default => 'critical',
        };
    }
}
