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
elif [ "$artifact_mode" = "tracer-only" ]; then
    # PHP 7.0 does not support profiling, but still ships the tracer.
    configure_products="--enable-ddtrace-tracer --disable-ddtrace-profiling"
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
# Avoid copying host build outputs into the isolated phpize build. These are
# regenerated there; copying multi-GB Cargo caches also slows local builds.
tar -cf - --exclude=.git --exclude=tmp --exclude=target --exclude=target-common \
    --exclude=target_mockgen --exclude=modules --exclude=.libs \
    --exclude='*.lo' --exclude='*.o' --exclude='*.la' --exclude='*.dep' \
    --exclude=Makefile.objects \
    --exclude=Makefile.fragments --exclude=config.status --exclude=config.log \
    --exclude=config.nice --exclude=autom4te.cache --exclude=libtool \
    --exclude=./configure --exclude=./configure.ac --exclude=./config.h \
    --exclude=./config.h.in --exclude=./run-tests.php \
    . | tar -xf - -C "${build_dir}/src"
cd "${build_dir}/src"
phpize
./configure ${configure_products}
# Make -s propagates to this make via MAKEFLAGS, but libtool emits its own
# progress messages. Pass --silent to libtool without overriding make's flags.
libtool_args=()
make_flags="${MAKEFLAGS:-}"
if [[ "${make_flags%% *}" =~ ^[^-]*s || " $make_flags " == *" --silent "* ]]; then
    libtool_args=("LIBTOOL=${SHELL:-/bin/sh} $PWD/libtool --silent")
fi
make -j"$(nproc)" "${libtool_args[@]}"
cp -v "modules/${extension_name}.so" "${output_file}"
objcopy --compress-debug-sections "${output_file}"
