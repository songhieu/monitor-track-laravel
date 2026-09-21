#!/usr/bin/env bash
# Laravel 9 scheduler with the http transport: two `schedule:run` a few
# seconds apart (the second finds harness:sleep still running), then wait for
# the background tasks and their `schedule:finish` processes.
source /tests/octane/harness/lib.sh
setup

mark run-1
php artisan schedule:run > /tmp/schedule-run-1.log 2>&1
sleep 3
mark run-2
php artisan schedule:run > /tmp/schedule-run-2.log 2>&1
sleep 12
mark done

php /tests/octane/harness/check.php schedule
