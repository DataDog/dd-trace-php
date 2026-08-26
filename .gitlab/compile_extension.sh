#!/usr/bin/env bash
set -xeo pipefail

SWITCH_PHP_VERSION=${SWITCH_PHP_VERSION:-}
WITH_ASAN=${WITH_ASAN:-}
CARGO_TARGET_DIR=${CARGO_TARGET_DIR:-target}
EXTENSION_DIR=${EXTENSION_DIR:-tmp/build_extension}
MODULES_DIR=${MODULES_DIR:-${EXTENSION_DIR}/modules}

# Generate VERSION with build id
./.gitlab/append-build-id.sh

# Change PHP versions if needed
if [ -n "${SWITCH_PHP_VERSION}" ]; then
  switch-php "${SWITCH_PHP_VERSION}"
fi

if [ "${WITH_ASAN}" -eq "1" ]; then
  export ASAN=1
  export COMPILE_ASAN=1
fi
# Compile Rust and PHP in parallel
SHARED=1 ./compile_rust.sh &
make -j static &
wait

# Link extension
#
# config.m4 already picks the right symbol list (datadog-all.sym, which
# also includes the Rust-exported symbols, when the Rust library is not
# split out; the platform-only datadog{,-linux}.sym otherwise) and writes
# it as the -export-symbols argument in ddtrace.ldflags.
export_symbols_file=$(grep -oE -- '-export-symbols [^ ]+' "${EXTENSION_DIR}/ddtrace.ldflags" | awk '{print $2}')
sed -i -E "s#-export-symbols [^ ]+#-Wl,--retain-symbols-file=${export_symbols_file}#g" "${EXTENSION_DIR}/ddtrace.ldflags"
cc -shared -Wl,-whole-archive ${MODULES_DIR}/ddtrace.a -Wl,-no-whole-archive $(cat ${EXTENSION_DIR}/ddtrace.ldflags) ${CARGO_TARGET_DIR}/debug/libdatadog_php.a -Wl,-soname -Wl,ddtrace.so -o ${MODULES_DIR}/ddtrace.so
