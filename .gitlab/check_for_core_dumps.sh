#!/usr/bin/env bash
set -eo pipefail

cd /usr/local/src/php
mkdir -p /tmp/artifacts/core_dumps
find ./ -name "core.*" | xargs -I % -n 1 cp % /tmp/artifacts/core_dumps
mkdir -p /tmp/artifacts/diffs
find -type f -name '*.diff' -exec cp --parents '{}' /tmp/artifacts/diffs \;
