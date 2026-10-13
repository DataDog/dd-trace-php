#!/usr/bin/env bash
set -e -o pipefail

shopt -s expand_aliases
source "${BASH_ENV}"

if [ -d '/opt/rh/devtoolset-7' ] ; then
    set +eo pipefail
    source scl_source enable devtoolset-7
    set -eo pipefail
fi
set -u

suffix="${1:-}"

# Workaround "error: failed to run custom build command for `aws-lc-sys v0.20.0`"
if [ "${suffix}" = "-alpine" ]; then
  cargo install --force --locked bindgen-cli
  export PATH="/root/.cargo/bin:$PATH"

  # Alpine has matched Clang/LLD and rust-src. Build the PHP-independent DSO
  # with the same cross-language release toolchain as its SSI extensions.
  export PHP_VERSION="$(php -n -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
  export CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-$PWD/target}"
  ./tooling/bin/build-xlang-lto ssi-common "$PWD/tmp/ssi-common"
  cp -v "$CARGO_TARGET_DIR/tracer-release/libdatadog_php.a" "libdatadog_php_$(uname -m)${suffix}.a"
  cp -v "$PWD/tmp/ssi-common/libdatadog_php.so" "libdatadog_php_$(uname -m)${suffix}.so"
  exit 0
fi

DDTRACE_PHP_INCLUDES="$(php-config --includes)" \
  CARGO_FEATURES='--no-default-features --features tracer-runtime' \
  SHARED=1 PROFILE=tracer-release host_os="${HOST_OS}" ./compile_rust.sh
cp -v "${CARGO_TARGET_DIR:-target}/tracer-release/libdatadog_php.a" "libdatadog_php_$(uname -m)${suffix}.a"
output="libdatadog_php_$(uname -m)${suffix}.so"
objcopy --compress-debug-sections "${CARGO_TARGET_DIR:-target}/tracer-release/libdatadog_php.so" "${output}"
