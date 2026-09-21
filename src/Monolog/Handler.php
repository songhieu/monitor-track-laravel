<?php

namespace MonitorTrack\Monolog;

use Monolog\Handler\AbstractHandler;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\Handler\ProcessableHandlerTrait;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use MonitorTrack\Client;

/*
 * Monolog handler that turns log records into monitor-track envelopes.
 *
 * A record whose context has an "exception" Throwable becomes type=exception
 * (with stack frames, never sampled); anything else becomes type=log (subject
 * to MT_SAMPLE_RATE). No formatter runs: the envelope is the format.
 *
 * Monolog 3 (Laravel 10+) and Monolog 2 (Laravel 9) have incompatible
 * handler signatures, so the class is declared for the installed major. Both
 * declarations live in this file so an authoritative classmap still finds it.
 */

if (Logger::API >= 3) {
    class Handler extends AbstractHandler implements ProcessableHandlerInterface
    {
        use HandlesRecords;
        use ProcessableHandlerTrait;

        public function __construct(?Client $client = null, int|string|Level $level = Level::Debug, bool $bubble = true)
        {
            $this->client = $client;
            parent::__construct($level, $bubble);
        }

        public function handle(LogRecord $record): bool
        {
            try {
                if (! $this->isHandling($record)) {
                    return false;
                }
                if ($this->processors !== []) {
                    $record = $this->processRecord($record);
                }
                $this->emit($record->message, $record->context, $record->extra, $record->level->value);
            } catch (\Throwable) {
                // never break the logger
            }

            return false === $this->bubble;
        }

        public static function level(Level $level): string
        {
            return self::levelName($level->value);
        }
    }
} else {
    class Handler extends AbstractHandler implements ProcessableHandlerInterface
    {
        use HandlesRecords;
        use ProcessableHandlerTrait;

        public function __construct(?Client $client = null, int|string $level = Logger::DEBUG, bool $bubble = true)
        {
            $this->client = $client;
            parent::__construct($level, $bubble);
        }

        /**
         * @param  array<string, mixed>  $record
         */
        public function handle(array $record): bool
        {
            try {
                if (! $this->isHandling($record)) {
                    return false;
                }
                if ($this->processors !== []) {
                    $record = $this->processRecord($record);
                }
                $this->emit((string) ($record['message'] ?? ''), (array) ($record['context'] ?? []),
                    (array) ($record['extra'] ?? []), (int) ($record['level'] ?? Logger::DEBUG));
            } catch (\Throwable) {
                // never break the logger
            }

            return false === $this->bubble;
        }

        public static function level(int $level): string
        {
            return self::levelName($level);
        }
    }
}
