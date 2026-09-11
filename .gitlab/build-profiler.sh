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
build_variant="${2:-nts}"
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

case "$build_variant" in
    zts)
        switch-php "${PHP_VERSION}-zts"
        default_output_name="${extension_name}-zts.so"
        ;;
    debug)
        switch-php "${PHP_VERSION}-debug"
        default_output_name="${extension_name}-debug.so"
        ;;
    nts)
        switch-php "${PHP_VERSION}"
        default_output_name="${extension_name}.so"
        ;;
    *)
        echo "Unsupported PHP build variant: ${build_variant}" >&2
        exit 1
        ;;
esac
output_file="${prefix}/${4:-${default_output_name}}"

# PHP debug ABI artifacts retain full Rust debug information while optimizing for size.
if [ "$build_variant" = "debug" ]; then
    export DDTRACE_RUST_PROFILE=php-debug
fi

# Loadable profiling artifacts must go through the supported PHP build path.
build_dir="/tmp/ddtrace-build-profiler-${build_variant}"
rm -rf "${build_dir}"
mkdir -p "${build_dir}/src"
tar -cf - --exclude=.git --exclude=tmp . | tar -xf - -C "${build_dir}/src"
cd "${build_dir}/src"
phpize
./configure ${configure_products}
make -j"$(nproc)"
cp -v "modules/${extension_name}.so" "${output_file}"
objcopy --compress-debug-sections "${output_file}"
