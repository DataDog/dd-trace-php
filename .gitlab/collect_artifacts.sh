#!/usr/bin/env bash
set -eo pipefail

[ -z "$1" ] || cd "$1"
mkdir -p "${CI_PROJECT_DIR}/artifacts/core_dumps"
find . -type f -name "core*" -exec head -c 4 "{}" \; -exec echo " {}" \;  | grep -a ^.ELF | cut -d' ' -f2 | xargs -I % -n 1 cp % "${CI_PROJECT_DIR}/artifacts/core_dumps" || true
mkdir -p "${CI_PROJECT_DIR}/artifacts/diffs"
find . -type f -name '*.diff' -not -path "*/vendor/*" -exec cp --parents '{}' "${CI_PROJECT_DIR}/artifacts/diffs" \; || true
mkdir -p "${CI_PROJECT_DIR}/artifacts/tests"
find . -type f -name '*.xml' -path "*/artifacts/tests/*" -exec cp '{}' "${CI_PROJECT_DIR}/artifacts/tests/" \; || true
