#!/bin/bash
set -euo pipefail

DOCKER_IMAGE="datadog/dd-appsec-php-ci:clang-tools"

GIT_ROOT=$(git rev-parse --show-toplevel 2>/dev/null || echo "$PWD")

WORK_DIR=$(pwd)
REL_WORK_DIR="${WORK_DIR#$GIT_ROOT}"
REL_WORK_DIR="${REL_WORK_DIR#/}"
if [ -z "$REL_WORK_DIR" ]; then
    REL_WORK_DIR="."
fi

ARGS=()
for arg in "$@"; do
    if [[ "$arg" == "$GIT_ROOT"* ]]; then
        REL_PATH="${arg#$GIT_ROOT}"
        REL_PATH="${REL_PATH#/}"
        ARGS+=("/workspace/$REL_PATH")
    elif [[ "$arg" == /* ]] && [[ -e "$arg" ]]; then
        if command -v realpath &> /dev/null; then
            REAL_PATH=$(realpath --relative-to="$WORK_DIR" "$arg" 2>/dev/null || echo "$arg")
            ARGS+=("$REAL_PATH")
        else
            ARGS+=("$arg")
        fi
    else
        ARGS+=("$arg")
    fi
done
