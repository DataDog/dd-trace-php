#!/usr/bin/env bash

set -o pipefail

log_file=${MAKE_INFRA_RETRY_LOG:-make.log}

make "$@" 2>&1 | tee "$log_file"
make_status=${PIPESTATUS[0]}

if ((make_status != 0)) &&
    grep -Fq 'realloc(): invalid pointer' "$log_file" &&
    grep -Fq 'linker command failed due to signal' "$log_file"; then
    echo "LLVM linker crashed; exiting 75 so GitLab retries the job"
    exit 75
fi

exit "$make_status"
