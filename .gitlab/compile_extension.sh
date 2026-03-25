#!/usr/bin/env bash
set -eo pipefail

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
./compile_rust.sh &
make -j static &
wait

# Compile solib_bootstrap.c separately: 'make static' uses --enable-ddtrace-rust-library-split
# (SSI flag) which excludes solib_bootstrap from ddtrace.a. For this non-SSI build we
# compile it here and inject it into the final link with the ELF entry point flag.
# solib_bootstrap.c uses only system headers, no PHP includes needed.
SOLIB_BOOTSTRAP_OBJ=${EXTENSION_DIR}/ext/solib_bootstrap.o
cc -c -fPIC -O2 -fvisibility=hidden -fno-stack-protector \
   ${EXTENSION_DIR}/ext/solib_bootstrap.c -o ${SOLIB_BOOTSTRAP_OBJ}

# Link extension
sed -i 's/-export-symbols .*\/ddtrace\.sym/-Wl,--retain-symbols-file=ddtrace.sym/g' ${EXTENSION_DIR}/ddtrace.ldflags
cc -shared -Wl,-whole-archive ${MODULES_DIR}/ddtrace.a ${SOLIB_BOOTSTRAP_OBJ} -Wl,-no-whole-archive $(cat ${EXTENSION_DIR}/ddtrace.ldflags) ${CARGO_TARGET_DIR}/debug/libddtrace_php.a -Wl,-e,_dd_solib_start -Wl,-soname -Wl,ddtrace.so -o ${MODULES_DIR}/ddtrace.so
# ExecSolib requires execute permission
chmod +x ${MODULES_DIR}/ddtrace.so
