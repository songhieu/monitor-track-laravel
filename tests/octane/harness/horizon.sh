#!/usr/bin/env bash
# Horizon 5 on redis with the http transport: N+1 jobs and failing jobs,
# then horizon:terminate.
source /tests/octane/harness/lib.sh
redis-server --daemonize yes --save '' > /dev/null
setup

php artisan horizon > /tmp/horizon.log 2>&1 &
horizon=$!
sleep 5

mark jobs
php artisan harness:dispatch 20
for _ in $(seq 1 60); do
    pgrep -f 'horizon:work' >> /tmp/horizon-workers.txt || true
    [ "$(grep -hoE '"status":"(done|failed)"' /tmp/ingest/* 2>/dev/null | wc -l)" -ge 20 ] && break
    sleep 0.5
done
for _ in $(seq 1 12); do pgrep -f 'horizon:work' >> /tmp/horizon-workers.txt || true; sleep 0.5; done
mark terminate
php artisan horizon:terminate > /dev/null
for _ in $(seq 1 60); do kill -0 $horizon 2>/dev/null || break; sleep 0.5; done
mark done

php /tests/octane/harness/check.php horizon
