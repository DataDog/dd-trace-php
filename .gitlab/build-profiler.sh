#!/usr/bin/env bash
set -e -o pipefail

shopt -s expand_aliases
source "${BASH_ENV}"

if [ -d '/opt/rh/devtoolset-7' ] ; then
    set +eo pipefail
    source scl_source enable devtoolset-7
    set -eo pipefail
fi

# On CentOS 7 aarch64, clang's resource dir isn't on the default include path,
# causing bindgen to fail with "stddef.h not found".
if [ -d '/opt/rh/devtoolset-7' ] && [ "$(uname -m)" = "aarch64" ]; then
    export BINDGEN_EXTRA_CLANG_ARGS="-I$(clang --print-resource-dir)/include"
fi

set -u

prefix="$1"
mkdir -vp "${prefix}"

# Check for thread safety mode argument
thread_safety="${2:-nts}"

# Switch PHP based on thread safety mode
if [ "$thread_safety" = "zts" ]; then
    switch-php "${PHP_VERSION}-zts"
    output_file="${prefix}/datadog-profiling-zts.so"
else
    switch-php "${PHP_VERSION}"
    output_file="${prefix}/datadog-profiling.so"
fi

cd profiling
CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-target}"
echo "${CARGO_TARGET_DIR}"
if [ "$thread_safety" = "zts" ]; then
    touch build.rs  # Ensure build.rs executes after switch-php for ZTS
fi
cargo build --profile profiler-release
cd -

cp -v "${CARGO_TARGET_DIR}/profiler-release/libdatadog_php_profiling.so" "${output_file}"
objcopy --compress-debug-sections "${output_file}"
