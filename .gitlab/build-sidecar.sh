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

# Install automake/libtool required by libdd-libunwind-sys build.rs (autoreconf step)
if [ "${suffix}" = "-alpine" ]; then
  apk add --no-cache automake libtool
elif command -v apt-get &>/dev/null; then
  apt-get install -y --no-install-recommends automake libtool
elif command -v yum &>/dev/null; then
  yum install -y automake libtool
fi

# Workaround "error: failed to run custom build command for `aws-lc-sys v0.20.0`"
if [ "${suffix}" = "-alpine" ]; then
  cargo install --force --locked bindgen-cli
  export PATH="/root/.cargo/bin:$PATH"
fi

SHARED=1 PROFILE=tracer-release host_os="${HOST_OS}" ./compile_rust.sh
cp -v "${CARGO_TARGET_DIR:-target}/tracer-release/libddtrace_php.a" "libddtrace_php_$(uname -m)${suffix}.a"
objcopy --compress-debug-sections "${CARGO_TARGET_DIR:-target}/tracer-release/libddtrace_php.so" "libddtrace_php_$(uname -m)${suffix}.so"
