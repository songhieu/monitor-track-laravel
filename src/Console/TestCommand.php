<?php

namespace MonitorTrack\Console;

use Illuminate\Console\Command;
use MonitorTrack\Client;

/**
 * `php artisan mt:test` — emits one event of each type and shows where they went.
 */
class TestCommand extends Command
{
    protected $signature = 'mt:test';

    protected $description = 'Send one monitor-track event of each type (log, exception, job, cron, heartbeat)';

    public function handle(Client $client): int
    {
        if (! $client->isEnabled()) {
            $this->warn('monitor-track is disabled (MT_ENABLED=false): nothing sent.');

            return self::SUCCESS;
        }

        $client->log('info', 'monitor-track test log from mt:test', ['source' => 'mt:test', 'password' => 'masked-by-sdk']);

        try {
            throw new \RuntimeException('monitor-track test exception from mt:test');
        } catch (\RuntimeException $e) {
            $client->captureException($e, ['source' => 'mt:test']);
        }

        $job = [
            'queue' => 'mt-test',
            'connection' => 'sync',
            'class' => 'MonitorTrack\\TestJob',
            'id' => bin2hex(random_bytes(16)),
            'attempts' => 1,
        ];
        $client->job('start', $job);
        $client->job('done', $job + ['runtime_ms' => 12]);

        // No "expr": a test run must not register a schedule that would then
        // raise missed-run alerts every minute.
        $cron = ['name' => 'mt:test', 'tz' => date_default_timezone_get()];
        $client->cron('start', $cron);
        $client->cron('success', $cron + ['exit_code' => 0, 'duration_ms' => 5]);

        $client->heartbeat(['queues' => ['mt-test'], 'state' => 'idle', 'processed' => 0]);

        $pending = $client->transport()->pending();
        $client->flush(3.0);

        $this->info('monitor-track test events sent.');
        $this->line('  transport:   '.$client->transport()->describe());
        if ($pending > 0) {
            $this->line("  flushed:     {$pending} buffered event(s)");
        }

        $stats = $client->stats();
        $this->table(['emitted', 'dropped', 'sampled', 'errors'], [array_values($stats)]);

        if ($stats['dropped'] > 0 || $stats['errors'] > 0) {
            $this->warn('Some events were dropped or failed; check MT_TRANSPORT / MT_ENDPOINT / MT_TOKEN / MT_FILE.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
