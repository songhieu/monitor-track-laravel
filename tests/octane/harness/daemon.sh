#!/usr/bin/env bash
# A long-running command listed in MT_LONG_RUNNING_COMMANDS: its events leave
# while it runs, and its repeated polling queries are no N+1.
source /tests/octane/harness/lib.sh
setup

mark start
MT_LONG_RUNNING_COMMANDS='harness:dae*' php artisan harness:daemon 7 > /tmp/daemon.log 2>&1
mark end
sleep 1

php /tests/octane/harness/check.php daemon
