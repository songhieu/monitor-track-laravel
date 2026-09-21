<?php

// Checks what the capture server received (/tmp/ingest) against what the
// scenario did. Usage: php check.php octane|schedule|horizon. Exit 1 on failure.

$mode = $argv[1] ?? '';
$failures = 0;

function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? '  ok    ' : '  FAIL  ').$what."\n";
    if (! $ok) {
        $failures++;
    }
}

/** @return list<array{at: float, events: list<array<string, mixed>>, request: array<string, mixed>}> */
function posts(): array
{
    $files = glob('/tmp/ingest/*.json') ?: [];
    sort($files);
    $posts = [];
    foreach ($files as $file) {
        $request = json_decode(file_get_contents($file), true);
        $events = [];
        foreach (explode("\n", rtrim((string) $request['body'], "\n")) as $line) {
            $events[] = ['_raw' => $line] + (json_decode($line, true) ?? []);
        }
        $posts[] = ['at' => (float) explode('-', basename($file))[0], 'events' => $events, 'request' => $request];
    }

    return $posts;
}

/** @return array<string, float> phase mark => time */
function phases(): array
{
    $marks = [];
    foreach (file('/tmp/phases.tsv', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        [$name, $at] = explode("\t", $line);
        $marks[$name] = (float) $at;
    }

    return $marks;
}

$posts = posts();
$events = array_merge([], ...array_column($posts, 'events'));
$marks = phases();

echo "{$mode}: ".count($posts).' POSTs, '.count($events)." events\n";

$wellFormed = true;
foreach ($posts as $post) {
    $r = $post['request'];
    $wellFormed = $wellFormed && $r['method'] === 'POST' && $r['uri'] === '/api/v1/logs/ingest'
        && $r['authorization'] === 'Bearer mt_src_harness' && $r['content_type'] === 'application/x-ndjson'
        && $r['content_encoding'] === 'gzip' && preg_match('/^[0-9a-f]{16}$/', (string) $r['idempotency_key']);
    foreach ($post['events'] as $e) {
        $wellFormed = $wellFormed && str_starts_with($e['_raw'], '{"_mt":1,"type":"') && ($e['app'] ?? null) === 'harness';
    }
}
check($posts !== [] && $wellFormed, 'every POST: /api/v1/logs/ingest, Bearer token, gzip NDJSON, {"_mt":1 lines');

$postsBetween = fn (string $from, string $to) => count(array_filter($posts, fn ($p) => $p['at'] >= $marks[$from] && $p['at'] < $marks[$to]));

if ($mode === 'octane') {
    $requests = [];
    foreach (file('/tmp/requests.tsv', FILE_IGNORE_NEW_LINES) as $line) {
        [$phase, $id, $route, $user, $code] = explode("\t", $line);
        $requests[$id] = compact('phase', 'id', 'route', 'user', 'code');
    }

    $byTrace = [];
    foreach ($events as $e) {
        $byTrace[$e['trace_id'] ?? '(none)'][] = $e;
    }

    $wrong = [];
    $nPlusOneCounts = [];
    foreach ($requests as $id => $r) {
        if ($r['phase'] === 'stop-now') {
            continue;
        }
        $mine = $byTrace[$id] ?? [];
        $expectedCode = $r['route'] === 'orders' ? '200' : '500';
        $ok = $r['code'] === $expectedCode && count($mine) === 1;
        if ($ok) {
            $e = $mine[0];
            $ok = ($e['route'] ?? null) === 'GET /api/'.$r['route'] && ($e['user_id'] ?? null) === $r['user'];
            if ($r['route'] === 'orders') {
                $q = $e['query'] ?? [];
                $nPlusOneCounts[] = $q['count'] ?? null;
                $ok = $ok && $e['type'] === 'query' && ($q['kind'] ?? null) === 'n_plus_one' && ($q['count'] ?? null) === 30
                    && ($q['scope'] ?? null) === 'request' && ($q['scope_name'] ?? null) === 'GET /api/orders';
            } else {
                $ok = $ok && $e['type'] === 'exception'
                    && $e['message'] === "RuntimeException: boom for user {$r['user']} in {$id}";
            }
        }
        if (! $ok) {
            $wrong[] = $id.' => '.json_encode(array_map(fn ($e) => [$e['type'] ?? null, $e['route'] ?? null, $e['user_id'] ?? null, $e['message'] ?? null], $mine));
        }
    }
    $checked = count(array_filter($requests, fn ($r) => $r['phase'] !== 'stop-now'));
    check($wrong === [], "{$checked} requests: each has exactly its own event (N+1 or exception) with its route, user_id and trace_id".($wrong ? "\n        ".implode("\n        ", array_slice($wrong, 0, 10)) : ''));
    check($nPlusOneCounts !== [] && array_unique($nPlusOneCounts) === [30], 'N+1 counted per request: '.count($nPlusOneCounts).' events, counts '.json_encode(array_values(array_unique($nPlusOneCounts))));

    $untraced = $byTrace['(none)'] ?? [];
    check($untraced === [], count($untraced).' events without a request context: '.json_encode(array_column($untraced, 'message')));

    $burstPids = array_unique(array_column(array_filter($events, fn ($e) => str_starts_with($e['trace_id'] ?? '', 'a-') || str_starts_with($e['trace_id'] ?? '', 'b-')), 'pid'));
    $burstPosts = $postsBetween('burst', 'idle');
    check($burstPosts <= 10 && count($burstPids) === 2, "burst: 40 requests on ".count($burstPids)." workers, {$burstPosts} POSTs (sent when due, not per request)");

    $idle = array_values(array_filter($posts, fn ($p) => in_array('idle-1', array_column($p['events'], 'trace_id'), true)));
    check(count($idle) === 1 && $idle[0]['at'] < $marks['idle-end'],
        'idle: the lone request\'s event left '.($idle ? sprintf('%.1f s', $idle[0]['at'] - $marks['idle']) : 'never').' later, with no request after it (Swoole timer)');

    $stops = array_map(fn ($l) => json_decode($l, true), file('/app/storage/logs/worker-stopping.log', FILE_IGNORE_NEW_LINES) ?: []);
    check(count($stops) >= 2, 'octane:reload: WorkerStopping in '.count($stops).' workers, buffered then: '.json_encode(array_column($stops, 'pending')));

    $stopIdle = count(array_filter($requests, fn ($r) => $r['phase'] === 'stop-idle' && isset($byTrace[$r['id']])));
    check($stopIdle === 4, "octane:stop 3 s after the last request: {$stopIdle}/4 requests' events delivered");

    $stopNow = count(array_filter($requests, fn ($r) => $r['phase'] === 'stop-now' && isset($byTrace[$r['id']])));
    echo "  info  octane:stop right after 4 requests: {$stopNow}/4 delivered (Octane stops Swoole with SIGKILL)\n";

    $console = file_get_contents('/tmp/octane.log');
    check(! str_contains($console, '"_mt"'), 'Octane console output ('.strlen($console).' bytes) has no SDK JSON');
}

if ($mode === 'schedule') {
    $cron = array_values(array_filter($events, fn ($e) => $e['type'] === 'cron'));
    $by = [];
    foreach ($cron as $e) {
        $by[$e['cron']['name']][] = $e;
    }
    $phasesOf = fn (string $name) => array_count_values(array_map(fn ($e) => $e['cron']['phase'], $by[$name] ?? []));
    foreach ($by as $name => $list) {
        echo "  info  {$name}: ".json_encode($phasesOf($name))."\n";
    }

    $runPids = array_unique(array_column(array_filter($cron, fn ($e) => in_array($e['cron']['phase'], ['start', 'skip'], true)), 'pid'));
    check(count($runPids) === 2, 'start/skip events come from the 2 schedule:run processes');

    $fails3 = array_filter($by['harness:exit 3'] ?? [], fn ($e) => $e['cron']['phase'] === 'fail');
    $okFail3 = count($fails3) === 2 && ($phasesOf('harness:exit 3')['start'] ?? 0) === 2;
    foreach ($fails3 as $e) {
        $okFail3 = $okFail3 && $e['cron']['exit_code'] === 3 && ! isset($e['cron']['duration_ms'])
            && ! in_array($e['pid'], $runPids, true) && $e['cron']['expr'] === '* * * * *' && $e['level'] === 'error';
    }
    check($okFail3, 'runInBackground exit 3: 2× start, then fail exit_code 3 from schedule:finish (no duration_ms)');

    $p0 = $phasesOf('harness:exit 0');
    $ok0 = ($p0['start'] ?? 0) + ($p0['skip'] ?? 0) === 2 && ($p0['success'] ?? 0) === ($p0['start'] ?? -1);
    foreach ($by['harness:exit 0'] ?? [] as $e) {
        $ok0 = $ok0 && ($e['cron']['phase'] !== 'success' || ($e['cron']['exit_code'] === 0 && ! in_array($e['pid'], $runPids, true)));
    }
    check($ok0, 'runInBackground + withoutOverlapping exit 0: every start has a success exit_code 0 from schedule:finish');

    check($phasesOf('harness:sleep 8') == ['start' => 1, 'skip' => 1, 'success' => 1], 'withoutOverlapping still running: start, skip (second schedule:run), success');

    $fg = array_filter($by['harness:exit 4 --foreground'] ?? [], fn ($e) => $e['cron']['phase'] === 'fail');
    $okFg = count($fg) === 2;
    foreach ($fg as $e) {
        $okFg = $okFg && $e['cron']['exit_code'] === 4 && isset($e['cron']['duration_ms']) && in_array($e['pid'], $runPids, true);
    }
    check($okFg, 'foreground exit 4: fail exit_code 4 with duration_ms from schedule:run');

    $perPid = [];
    foreach ($posts as $post) {
        foreach (array_unique(array_column($post['events'], 'pid')) as $pid) {
            $perPid[$pid] = ($perPid[$pid] ?? 0) + 1;
        }
    }
    $finishPids = array_diff(array_unique(array_column($cron, 'pid')), $runPids);
    check(array_unique(array_values($perPid)) === [1] && count($finishPids) >= 5,
        count($perPid).' processes ('.count($runPids).' schedule:run, '.count($finishPids).' schedule:finish) each sent its buffer in one POST when it ended');
}

if ($mode === 'horizon') {
    $jobs = [];
    foreach ($events as $e) {
        if ($e['type'] === 'job') {
            $jobs[$e['job']['id']][] = $e;
        }
    }
    $okJobs = count($jobs) === 20;
    $classes = [];
    foreach ($jobs as $list) {
        $statuses = array_map(fn ($e) => $e['job']['status'], $list);
        $class = $list[0]['job']['class'];
        $classes[$class] = ($classes[$class] ?? 0) + 1;
        $expected = $class === 'App\Jobs\FailingJob' ? ['start', 'failed'] : ['start', 'done'];
        $okJobs = $okJobs && $statuses === $expected;
    }
    check($okJobs, count($jobs).' jobs, each start then done / failed: '.json_encode($classes));

    $queries = array_values(array_filter($events, fn ($e) => $e['type'] === 'query'));
    $okQ = count($queries) === 16;
    foreach ($queries as $e) {
        $okQ = $okQ && $e['query']['kind'] === 'n_plus_one' && $e['query']['count'] === 30
            && $e['query']['scope'] === 'job' && $e['query']['scope_name'] === 'App\Jobs\LoadCustomers';
    }
    check($okQ, count($queries).' N+1 events, one per LoadCustomers job, count 30 each');

    $exceptions = array_filter($events, fn ($e) => $e['type'] === 'exception');
    check(count($exceptions) === 4, count($exceptions).' exceptions for the 4 failing jobs');

    $workers = array_values(array_unique(array_map('intval', file('/tmp/horizon-workers.txt', FILE_IGNORE_NEW_LINES) ?: [])));
    $beats = array_filter($events, fn ($e) => $e['type'] === 'heartbeat');
    $beatPids = array_values(array_unique(array_column($beats, 'pid')));
    check($beats !== [] && array_diff($beatPids, $workers) === [], count($beats).' heartbeats, from '.count($beatPids).' horizon:work processes, none from the master or supervisors ('.count($workers).' workers seen)');

    $jobPosts = $postsBetween('jobs', 'terminate');
    echo "  info  {$jobPosts} POSTs while jobs ran, ".$postsBetween('terminate', 'done')." after horizon:terminate\n";
}

if ($mode === 'daemon') {
    $logs = array_values(array_filter($events, fn ($e) => $e['type'] === 'log'));
    check(array_column($logs, 'message') === array_map(fn ($i) => "daemon loop {$i}", range(1, 7)), count($logs).' loop events, in order');

    $during = array_filter($posts, fn ($p) => $p['at'] < $marks['end']);
    $sentDuring = count(array_filter(array_merge([], ...array_column($during, 'events')), fn ($e) => $e['type'] === 'log'));
    check(count($during) >= 2 && $sentDuring >= 5, count($posts).' POSTs, '.count($during)." while it ran, carrying {$sentDuring} of the 7 events");

    $queries = array_filter($events, fn ($e) => $e['type'] === 'query');
    check($queries === [], '210 repeated polling queries (30 × 7): '.count($queries).' N+1 events at exit');
}

echo $failures === 0 ? "{$mode}: PASS\n" : "{$mode}: {$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
