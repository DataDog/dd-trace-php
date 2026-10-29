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
fi

# Both release images have matched Clang/LLD and rust-src. The common DSO is
# PHP-independent, but must use the same cross-language toolchain as ddtrace.so.
export PHP_VERSION="$(php -n -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
export CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-$PWD/tmp/ssi-rust-target}"
./tooling/bin/build-xlang-lto ssi-common "$PWD/tmp/ssi-common"

# build-std uses an explicit native target. Musl also needs --target when
# build-std is disabled, so its build scripts can dynamically load libclang.
archive_dir="$CARGO_TARGET_DIR"
if [[ "${DDTRACE_XLANG_BUILD_STD:-1}" == 1 || "$suffix" == "-alpine" ]]; then
  archive_dir+="/$(rustc -vV | awk '/^host: / { print $2 }')"
fi
# This archive is an intermediate input to the legacy PHP 7.0 debug link,
# never an SSI package member. It now contains LLVM IR on both libc targets.
cp -v "$archive_dir/tracer-release/libdatadog_php_common.a" "libdatadog_php_$(uname -m)${suffix}.a"
cp -v "$PWD/tmp/ssi-common/libdatadog_php.so" "libdatadog_php_$(uname -m)${suffix}.so"
