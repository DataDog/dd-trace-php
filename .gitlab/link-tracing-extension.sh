#!/usr/bin/env bash
set -e -o pipefail

suffix="${1:-}"
source .gitlab/legacy-measure.sh

pids=()
for archive in extensions_$(uname -m)/*.a; do
  (
    measure_legacy link "${TRIPLET:-local}-$(basename "${archive%.a}")" \
      cc -shared -Wl,-whole-archive $archive -Wl,-no-whole-archive \
      $(cat "ddtrace_$(uname -m)${suffix}-fat.ldflags") \
      -Wl,--retain-symbols-file="ddtrace_$(uname -m)${suffix}-fat.sym" \
      "libdatadog_php_$(uname -m)${suffix}.a" \
      -Wl,-soname -Wl,ddtrace.so -o ${archive%.a}.so
    objcopy --compress-debug-sections ${archive%.a}.so
  ) &
  pids+=($!)
done
for pid in "${pids[@]}"; do
  wait $pid
done
