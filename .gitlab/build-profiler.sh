#!/usr/bin/env bash
set -e -o pipefail

shopt -s expand_aliases
source "${BASH_ENV}"

if [ -d '/opt/rh/devtoolset-7' ] ; then
    set +eo pipefail
    source scl_source enable devtoolset-7
    set -eo pipefail
fi

# With clang 20, bindgen fails on aarch64:
#  /usr/lib/llvm20/lib/clang/20/include/arm_vector_types.h:20:9: error: unknown type name '__mfp8'
#  /usr/lib/llvm20/lib/clang/20/include/arm_vector_types.h:93:24: error: Neon vector size must be 64 or 128 bits
#  /usr/lib/llvm20/lib/clang/20/include/arm_vector_types.h:94:24: error: Neon vector size must be 64 or 128 bits
#  /usr/lib/llvm20/lib/clang/20/include/arm_neon.h:6374:25: error: incompatible constant for this __builtin_neon function
# etc.
if [ -f /sbin/apk ] && [ $(uname -m) = "aarch64" ]; then
    ln -sf ../lib/llvm17/bin/clang /usr/bin/clang
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
RUSTFLAGS="-L native=$(dirname "$(gcc -print-file-name=libssp_nonshared.a)")" RUSTC_BOOTSTRAP=1 cargo build -Zbuild-std=std,panic_abort --target "${TRIPLET:?}" --profile profiler-release
cd -

cp -v "${CARGO_TARGET_DIR}/${TRIPLET}/profiler-release/libdatadog_php_profiling.so" "${output_file}"
objcopy --compress-debug-sections "${output_file}"
