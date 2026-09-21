#!/usr/bin/env bash
# Runs the SDK in a real Laravel 9 + Octane (Swoole) / scheduler / Horizon app
# with the http transport, and checks what an ingest capture server received.
#
#   tests/octane/run.sh [octane|schedule|horizon|daemon|all]   default: all
#
#   SDK=/path/to/sdk     mount another copy of the SDK (default: this one)
#   REBUILD=1            rebuild the image
#   CLEAN=1              remove the image afterwards
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
SDK=$(cd "${SDK:-$HERE/../..}" && pwd)
IMAGE=mt-octane-harness
scenarios=${1:-all}
[[ $scenarios == all ]] && scenarios="octane schedule horizon daemon"

if [[ -n ${REBUILD:-} ]] || ! docker image inspect "$IMAGE" > /dev/null 2>&1; then
    # Only what composer needs to install the SDK; the live source is mounted.
    ctx=$(mktemp -d)
    trap 'rm -rf "$ctx"' EXIT
    cp -R "$HERE/../../composer.json" "$HERE/../../src" "$HERE/../../config" "$ctx/"
    docker build --build-context sdk="$ctx" -t "$IMAGE" "$HERE"
fi

status=0
for scenario in $scenarios; do
    echo "== $scenario (SDK: $SDK)"
    docker run --rm -v "$SDK":/sdk:ro -v "$HERE/..":/tests:ro "$IMAGE" bash "/tests/octane/harness/$scenario.sh" || status=1
done

if [[ -n ${CLEAN:-} ]]; then
    docker rmi "$IMAGE" > /dev/null
fi

exit $status
