#!/usr/bin/env bash
set -e -o pipefail

suffix="${1:-}"

sed -i 's/-export-symbols .*\/ddtrace\.sym/-Wl,--retain-symbols-file=ddtrace.sym/g' "ddtrace_$(uname -m)${suffix}.ldflags"
pids=()
for archive in extensions_$(uname -m)/*.a; do
  (
    cc -shared -Wl,-whole-archive $archive -Wl,-no-whole-archive $(cat "ddtrace_$(uname -m)${suffix}.ldflags") "libddtrace_php_$(uname -m)${suffix}.a" -Wl,-soname -Wl,ddtrace.so -o ${archive%.a}.so
    objcopy --compress-debug-sections ${archive%.a}.so
  ) &
  pids+=($!)
done
for pid in "${pids[@]}"; do
  wait $pid
done

# Place datadog-ipc-helper in the extensions directory so it travels with ddtrace.so
# through the artifact pipeline and gets installed alongside it.
cp "datadog-ipc-helper$(uname -m)${suffix}" "extensions_$(uname -m)/datadog-ipc-helper"
