#!/usr/bin/env bash
set -e -o pipefail

architecture="$(uname -m)"
compiler="${CC:-cc}"
read -r -a ldflags < "ddtrace_${architecture}-fat.ldflags"
pids=()
for archive in "extensions_${architecture}"/*.a; do
  (
    output="${archive%.a}.so"
    "$compiler" -shared -Wl,-whole-archive "$archive" \
      -Wl,-no-whole-archive \
      "${ldflags[@]}" \
      -Wl,--retain-symbols-file="ddtrace_${architecture}-fat.sym" \
      "libdatadog_php_${architecture}.a" \
      -Wl,-soname -Wl,ddtrace.so -o "$output"
    objcopy --compress-debug-sections "$output"
    if readelf --version-info "$output" | grep GLIBC_ >/dev/null; then
      echo "$output is not portable: found a GLIBC symbol version" >&2
      exit 1
    fi
    rm -f "$archive"
  ) &
  pids+=($!)
done
for pid in "${pids[@]}"; do
  wait "$pid"
done
