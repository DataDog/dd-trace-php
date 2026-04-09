#!/usr/bin/env bash
set -e -o pipefail

suffix="${1:-}"

sed -i 's/-export-symbols .*\/ddtrace\.sym/-Wl,--retain-symbols-file=ddtrace.sym/g' "ddtrace_$(uname -m)${suffix}.ldflags"

# Compile solib_bootstrap.c: the split build (--enable-ddtrace-rust-library-split) excludes
# it from the per-PHP-version .a archives, but the final ddtrace.so still needs it as the
# ELF entry point for ExecSolib sidecar spawning.
SOLIB_BOOTSTRAP_OBJ=$(mktemp --suffix=.o)
cc -c -fPIC -O2 -fvisibility=hidden -fno-stack-protector \
   ext/solib_bootstrap.c -o "${SOLIB_BOOTSTRAP_OBJ}"

pids=()
for archive in extensions_$(uname -m)/*.a; do
  (
    cc -shared -Wl,-whole-archive $archive "${SOLIB_BOOTSTRAP_OBJ}" -Wl,-no-whole-archive $(cat "ddtrace_$(uname -m)${suffix}.ldflags") "libddtrace_php_$(uname -m)${suffix}.a" -Wl,-e,_dd_solib_start -Wl,-soname -Wl,ddtrace.so -o ${archive%.a}.so
    objcopy --compress-debug-sections ${archive%.a}.so
    chmod +x ${archive%.a}.so
  ) &
  pids+=($!)
done
for pid in "${pids[@]}"; do
  wait $pid
done
rm -f "${SOLIB_BOOTSTRAP_OBJ}"
