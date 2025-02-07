#!/usr/bin/env bash
set -eo pipefail

cd /usr/local/src/php
mkdir -p "${CI_PROJECT_DIR}/artifacts/core_dumps"
find ./ -name "core.*" | xargs -I % -n 1 cp % "${CI_PROJECT_DIR}/artifacts/core_dumps"
mkdir -p "${CI_PROJECT_DIR}/artifacts/diffs"
find -type f -name '*.diff' -exec cp --parents '{}' "${CI_PROJECT_DIR}/artifacts/diffs" \;
