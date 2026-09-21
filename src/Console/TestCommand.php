<?php

namespace MonitorTrack\Console;

use Illuminate\Console\Command;
use MonitorTrack\Client;
use MonitorTrack\Transport\StreamTransport;

/**
 * `php artisan mt:test` — sends a log, an exception, a job, a cron run and a heartbeat
 * (not a query sample: those need a real slow query or N+1) and shows where they went.
 */
class TestCommand extends Command
{
    protected $signature = 'mt:test
        {--pod-log : Write to the container\'s own stderr, so the collector sees events sent through kubectl exec}';

    /**
     * The container's main process stderr, i.e. the pod log (overridable in tests).
     */
    public static string $podLog = '/proc/1/fd/2';

    /** @var resource|null */
    private $pipe = null;

    protected $description = 'Send one monitor-track event of each type (log, exception, job, cron, heartbeat)';

    public function handle(Client $client): int
    {
        if (! $client->isEnabled()) {
            $this->warn('monitor-track is disabled (MT_ENABLED=false): nothing sent.');

            return self::SUCCESS;
        }

        $podLog = (bool) $this->option('pod-log');
        if ($podLog) {
            if (! $client->transport() instanceof StreamTransport) {
                $this->warn('--pod-log only applies to MT_TRANSPORT=stream; sending through '.$client->transport()->describe().'.');
                $podLog = false;
            } else {
                $h = $this->openPodLog();
                if ($h === false) {
                    $this->error('Cannot write to '.self::$podLog.' (the container\'s stderr). Run as the same user as the main process, or pipe: php artisan mt:test 2>'.self::$podLog);

                    return self::FAILURE;
                }
                $client->streamTo($h);
            }
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
        if ($podLog && $this->pipe !== null) {
            @pclose($this->pipe); // waits for the shell to write everything
        }

        $this->info('monitor-track test events sent.');
        $this->line('  transport:   '.($podLog ? 'stream '.self::$podLog.' (pod log)' : $client->transport()->describe()));
        if (! $podLog && $client->transport() instanceof StreamTransport
            && getenv('KUBERNETES_SERVICE_HOST') !== false && getmypid() !== 1) {
            // kubectl exec gives the command its own stderr, which is not the
            // container log the collector reads.
            $this->line('  note:        inside a pod? Through kubectl exec these lines went to this command\'s');
            $this->line('               output, not the pod log. Re-run with --pod-log so the collector sees them.');
        }
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

    /**
     * @return resource|false
     */
    private function openPodLog()
    {
        $path = self::$podLog;
        $h = @fopen($path, 'ab');
        if ($h !== false) {
            return $h;
        }

        // PHP resolves /proc/1/fd/2 to "pipe:[…]", which is not a path, so
        // let the shell open it. Check first that it can: writing into a
        // pipe whose reader died would kill this command.
        if (! function_exists('popen') || ! function_exists('exec')) {
            return false;
        }
        $target = escapeshellarg($path);
        @exec('sh -c '.escapeshellarg(': >> '.$target).' 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return false;
        }
        $pipe = @popen('cat >> '.$target, 'w');
        if ($pipe === false) {
            return false;
        }

        return $this->pipe = $pipe;
    }
}
