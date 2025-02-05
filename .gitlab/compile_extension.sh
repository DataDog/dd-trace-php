#!/usr/bin/env bash
set -eo pipefail

SWITCH_PHP_VERSION=${SWITCH_PHP_VERSION:-}
ASAN=${ASAN:-}
CARGO_TARGET_DIR=${CARGO_TARGET_DIR:-target}
EXTENSION_DIR=${EXTENSION_DIR:-tmp/build_extension}
MODULES_DIR=${MODULES_DIR:-${EXTENSION_DIR}/modules}

# Generate VERSION with build id
./.gitlab/append-build-id.sh

# Change PHP versions if needed
if [ -n "${SWITCH_PHP_VERSION}" ]; then
  switch-php "${SWITCH_PHP_VERSION}"
fi

if [ -n "${ASAN}" ]; then
  export COMPILE_ASAN=1
fi
# Compile Rust and PHP in parallel
./compile_rust.sh &
make -j static &
wait

# Link extension
sed -i 's/-export-symbols .*\/ddtrace\.sym/-Wl,--retain-symbols-file=ddtrace.sym/g' ${EXTENSION_DIR}/ddtrace.ldflags
cc -shared -Wl,-whole-archive ${MODULES_DIR}/ddtrace.a -Wl,-no-whole-archive $(cat ${EXTENSION_DIR}/ddtrace.ldflags) ${CARGO_TARGET_DIR}/debug/libddtrace_php.a -Wl,-soname -Wl,ddtrace.so -o ${MODULES_DIR}/ddtrace.so
