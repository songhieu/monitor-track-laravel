<?php

namespace MonitorTrack\Monolog;

use Illuminate\Container\Container;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Handler\ProcessableHandlerTrait;
use Monolog\Level;
use Monolog\LogRecord;
use MonitorTrack\Client;

/**
 * Monolog 3 handler that turns log records into monitor-track envelopes.
 *
 * A record whose context has an "exception" Throwable becomes type=exception
 * (with stack frames, never sampled); anything else becomes type=log (subject
 * to MT_SAMPLE_RATE). No formatter runs: the envelope is the format.
 */
class Handler extends AbstractHandler implements ProcessableHandlerInterface
{
    use ProcessableHandlerTrait;

    public function __construct(
        private ?Client $client = null,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    public function handle(LogRecord $record): bool
    {
        try {
            if (! $this->isHandling($record)) {
                return false;
            }

            $client = $this->client();
            if ($client === null || ! $client->isEnabled()) {
                return false === $this->bubble;
            }

            if ($this->processors !== []) {
                $record = $this->processRecord($record);
            }

            $context = $record->context;
            if ($record->extra !== []) {
                $context['extra'] = $record->extra;
            }

            $level = self::level($record->level);
            $exception = $context['exception'] ?? null;

            if ($exception instanceof \Throwable) {
                unset($context['exception']);
                if ($record->message !== '' && $record->message !== $exception->getMessage()) {
                    $context['log_message'] = $record->message;
                }
                $client->captureException($exception, $context, $level === 'critical' ? 'critical' : 'error');
            } else {
                $client->log($level, $record->message, $context);
            }
        } catch (\Throwable) {
            // never break the logger
        }

        return false === $this->bubble;
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

    public static function level(Level $level): string
    {
        return match ($level) {
            Level::Debug => 'debug',
            Level::Info => 'info',
            Level::Notice => 'notice',
            Level::Warning => 'warning',
            Level::Error => 'error',
            default => 'critical',
        };
    }
}
