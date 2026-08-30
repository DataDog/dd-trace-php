#!/usr/bin/env bash
set -e -o pipefail

shopt -s expand_aliases
source "${BASH_ENV}"

if [ -d '/opt/rh/devtoolset-7' ] ; then
    set +eo pipefail
    source scl_source enable devtoolset-7
    set -eo pipefail
fi
if [ -d '/opt/rh/devtoolset-7' ] && [ "$(uname -m)" = "aarch64" ]; then
    export BINDGEN_EXTRA_CLANG_ARGS="-I$(clang --print-resource-dir)/include"
fi

set -u
prefix="$1"
thread_safety="${2:-nts}"
artifact_mode="${3:-standalone}"
mkdir -vp "${prefix}"
prefix="$(cd "${prefix}" && pwd)"

if [ "$artifact_mode" = "combined" ]; then
    configure_products="--enable-ddtrace-tracer --enable-ddtrace-profiling"
    extension_name="ddtrace"
else
    configure_products="--disable-ddtrace-tracer --enable-ddtrace-profiling"
    extension_name="datadog-profiling"
fi

if [ "$thread_safety" = "zts" ]; then
    switch-php "${PHP_VERSION}-zts"
    output_file="${prefix}/${extension_name}-zts.so"
else
    switch-php "${PHP_VERSION}"
    output_file="${prefix}/${extension_name}.so"
fi

# Loadable profiling artifacts must go through the supported PHP build path.
build_dir="/tmp/ddtrace-build-profiler-${thread_safety}"
rm -rf "${build_dir}"
mkdir -p "${build_dir}/src"
tar -cf - --exclude=.git --exclude=tmp . | tar -xf - -C "${build_dir}/src"
cd "${build_dir}/src"
phpize
./configure ${configure_products}
make -j"$(nproc)"
cp -v "modules/${extension_name}.so" "${output_file}"
objcopy --compress-debug-sections "${output_file}"
