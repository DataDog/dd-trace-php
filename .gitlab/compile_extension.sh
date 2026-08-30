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
# Compile Rust and PHP in parallel. This split-library build links the Rust
# archive manually, so pass the PHP toolchain selected above explicitly.
DDTRACE_PHP_INCLUDES="$(php-config --includes)" SHARED=1 ./compile_rust.sh &
rust_pid=$!
make -j static &
php_pid=$!

build_status=0
wait "${rust_pid}" || build_status=$?
wait "${php_pid}" || build_status=$?
test "${build_status}" -eq 0

# Link extension
if [ "$(uname -s)" = "Linux" ]; then
  export_symbols_file="datadog-linux.sym"
else
  export_symbols_file="datadog.sym"
fi
sed -i -E "s#-export-symbols [^ ]+#-Wl,--retain-symbols-file=${export_symbols_file}#g" "${EXTENSION_DIR}/ddtrace.ldflags"
cc -shared -Wl,-whole-archive ${MODULES_DIR}/ddtrace.a -Wl,-no-whole-archive $(cat ${EXTENSION_DIR}/ddtrace.ldflags) ${CARGO_TARGET_DIR}/debug/libdatadog_php.a -Wl,-soname -Wl,ddtrace.so -o ${MODULES_DIR}/ddtrace.so
