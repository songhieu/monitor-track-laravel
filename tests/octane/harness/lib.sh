# Shared by the scenarios. The app runs with the http transport against a
# capture server on 127.0.0.1:9999 (tests/Fixtures/ingest-server.php), which
# writes every POST it receives to /tmp/ingest.
set -euo pipefail
cd /app

export APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stack OCTANE_SERVER=swoole
export DB_CONNECTION=sqlite DB_DATABASE=/app/database/database.sqlite
export CACHE_DRIVER=file SESSION_DRIVER=array QUEUE_CONNECTION=redis REDIS_CLIENT=phpredis REDIS_HOST=127.0.0.1
export MT_TRANSPORT=http MT_ENDPOINT=http://127.0.0.1:9999 MT_TOKEN=mt_src_harness MT_APP=harness
export MT_QUERY_THROTTLE_SECONDS=0 MT_HEARTBEAT_SECONDS=2

now() { php -r 'echo sprintf("%.3f", microtime(true));'; }

# phase name, start, end: lets check.php attribute POSTs to phases.
mark() { echo -e "$1\t$(now)" >> /tmp/phases.tsv; }

setup() {
    rm -rf /tmp/ingest /tmp/phases.tsv /tmp/requests.tsv storage/logs/*.log
    mkdir -p /tmp/ingest
    rm -f database/database.sqlite && touch database/database.sqlite
    php artisan migrate --force > /tmp/migrate.log 2>&1

    MT_TEST_DIR=/tmp/ingest PHP_CLI_SERVER_WORKERS=4 \
        php -S 127.0.0.1:9999 /tests/Fixtures/ingest-server.php > /tmp/ingest-server.log 2>&1 &
    wait_for http://127.0.0.1:9999/
    # The probe itself was recorded: start from an empty capture.
    rm -f /tmp/ingest/*
}

wait_for() {
    for _ in $(seq 1 100); do
        curl -s -o /dev/null "$1" && return 0
        sleep 0.2
    done
    echo "timeout waiting for $1" >&2
    return 1
}

posts() { ls /tmp/ingest | wc -l | tr -d ' '; }
