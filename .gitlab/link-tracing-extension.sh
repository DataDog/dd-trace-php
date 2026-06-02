#!/usr/bin/env bash
set -e -o pipefail

suffix="${1:-}"

sed -i 's/-export-symbols .*\/datadog\.sym/-Wl,--retain-symbols-file=datadog.sym/g' "ddtrace_$(uname -m)${suffix}.ldflags"
pids=()
for archive in extensions_$(uname -m)/*.a; do
  (
    cc -shared -Wl,-whole-archive $archive -Wl,-no-whole-archive $(cat "ddtrace_$(uname -m)${suffix}.ldflags") "libdatadog_php_$(uname -m)${suffix}.a" -Wl,-soname -Wl,ddtrace.so -o ${archive%.a}.so
    objcopy --compress-debug-sections ${archive%.a}.so
  ) &
  pids+=($!)
done
for pid in "${pids[@]}"; do
  wait $pid
done
