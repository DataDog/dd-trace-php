#!/usr/bin/env bash
set -e -o pipefail

set -u

architecture="$(uname -m)"
rust_target="${architecture}-unknown-linux-musl"
export CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-target-portable}"

# Build the Rust library once per architecture. Linking the musl target
# dynamically, with a static unwind library, produces one library that can be
# loaded by both glibc and musl processes.
RUSTFLAGS='-C link-arg=/usr/lib/libunwind.a -C force-unwind-tables=yes' \
  SHARED=1 PROFILE=tracer-release host_os=linux-musl \
  ./compile_rust.sh \
    --package datadog-php \
    --package php_sidecar_mockgen \
    -Z build-std=std,panic_abort \
    -Z build-std-features=llvm-libunwind,backtrace

library_dir="${CARGO_TARGET_DIR}/tracer-release"
shared_library="libdatadog_php_${architecture}.so"
cp -v "${library_dir}/libdatadog_php.a" \
  "libdatadog_php_${architecture}.a"
objcopy --compress-debug-sections \
  "${library_dir}/libdatadog_php.so" \
  "${shared_library}"
if readelf --version-info "${shared_library}" | grep GLIBC_ >/dev/null; then
  echo "${shared_library} is not portable: found a GLIBC symbol version" >&2
  exit 1
fi
cp -v \
  "${CARGO_TARGET_DIR}/${rust_target}/tracer-release/php_sidecar_mockgen" \
  "php_sidecar_mockgen_${architecture}"
