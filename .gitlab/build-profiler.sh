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
mkdir -vp "${prefix}"
prefix="$(cd "${prefix}" && pwd)"

if [ "$thread_safety" = "zts" ]; then
    switch-php "${PHP_VERSION}-zts"
    output_file="${prefix}/datadog-profiling-zts.so"
else
    switch-php "${PHP_VERSION}"
    output_file="${prefix}/datadog-profiling.so"
fi

# Loadable profiler artifacts must go through the supported PHP build path.
build_dir="/tmp/ddtrace-build-profiler-${thread_safety}"
rm -rf "${build_dir}"
mkdir -p "${build_dir}/src"
tar -cf - --exclude=.git --exclude=tmp . | tar -xf - -C "${build_dir}/src"
cd "${build_dir}/src"
phpize
./configure --disable-ddtrace-tracer --enable-ddtrace-profiling
make -j"$(nproc)"
cp -v modules/datadog-profiling.so "${output_file}"
objcopy --compress-debug-sections "${output_file}"
