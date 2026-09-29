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
rust_build_log=$(mktemp)
trap 'rm -f "$rust_build_log"' EXIT
SHARED=1 ./compile_rust.sh 2>&1 | tee "$rust_build_log" &
rust_build_pid=$!
make -j static &
c_build_pid=$!

# A bare wait discards the exit statuses of both background builds.
rust_build_status=0
c_build_status=0
wait "$rust_build_pid" || rust_build_status=$?
wait "$c_build_pid" || c_build_status=$?
if [ "$c_build_status" -ne 0 ]; then
  exit "$c_build_status"
fi
if [ "$rust_build_status" -ne 0 ]; then
  # Retry the job with a clean target directory: libddwaf may have left a
  # partially extracted archive, which cannot safely be reused locally.
  if grep -Eq 'Failed to (download archive|write archive entry contents to file):.*reqwest::Error.*(ConnectionReset|ConnectionAborted|TimedOut|IncompleteMessage|UnexpectedEof)' "$rust_build_log"; then
    echo "Transient libddwaf download failure; exiting 75 for GitLab retry." >&2
    exit 75
  fi
  exit "$rust_build_status"
fi

# Link extension
cc -shared -Wl,-whole-archive "${MODULES_DIR}/ddtrace.a" \
  -Wl,-no-whole-archive $(cat "${EXTENSION_DIR}/ddtrace-fat.ldflags") \
  -Wl,--retain-symbols-file="${EXTENSION_DIR}/ddtrace-fat.sym" \
  "${CARGO_TARGET_DIR}/debug/libdatadog_php.a" \
  -Wl,-soname -Wl,ddtrace.so -o "${MODULES_DIR}/ddtrace.so"
