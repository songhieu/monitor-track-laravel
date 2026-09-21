#!/usr/bin/env bash
# Octane (Swoole, 2 workers) with the http transport: interleaved requests of
# two users on two routes, then the idle flush, a graceful worker restart
# (octane:reload) and octane:stop.
source /tests/octane/harness/lib.sh
setup

start_octane() {
    php artisan octane:start --server=swoole --host=127.0.0.1 --port=8000 --workers=2 --task-workers=1 \
        --max-requests=500 >> /tmp/octane.log 2>&1 &
    wait_for http://127.0.0.1:8000/api/ping
}

# phase, request id, route, user
req() {
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' -H "X-Request-Id: $2" -H "X-User-Id: $4" \
        -H 'Accept: application/json' "http://127.0.0.1:8000/api/$3")
    echo -e "$1\t$2\t$3\t$4\t$code" >> /tmp/requests.tsv
}

start_octane

mark burst
for i in $(seq 1 20); do
    # Two requests at once (both workers busy), users and routes alternating.
    if (( i % 2 )); then
        req burst "a-$i" orders 1 & p1=$!; req burst "b-$i" boom 2 & p2=$!
    else
        req burst "a-$i" boom 1 & p1=$!; req burst "b-$i" orders 2 & p2=$!
    fi
    wait $p1 $p2
done
mark burst-end
sleep 3
echo "burst: 40 requests, $(posts) POSTs after 3 s idle"

mark idle
req idle idle-1 boom 1
sleep 3.5
echo "idle: $(grep -l '"idle-1' /tmp/ingest/* | wc -l | tr -d ' ') POST(s) carry idle-1 with no request after it"
mark idle-end

mark reload
for i in 1 2 3 4; do req reload "r-$i" orders $(( i % 2 + 1 )); done
php artisan octane:reload > /dev/null
sleep 4
req reload r-after orders 1
sleep 3
mark reload-end
echo "reload: $(wc -l < storage/logs/worker-stopping.log | tr -d ' ') WorkerStopping(s): $(tr '\n' ' ' < storage/logs/worker-stopping.log)"

mark stop-idle
for i in 1 2 3 4; do req stop-idle "s-$i" boom $(( i % 2 + 1 )); done
sleep 3
php artisan octane:stop > /dev/null
sleep 1
mark stop-idle-end

start_octane
mark stop-now
for i in 1 2 3 4; do req stop-now "k-$i" boom $(( i % 2 + 1 )); done
php artisan octane:stop > /dev/null
sleep 2
mark stop-now-end

php /tests/octane/harness/check.php octane
