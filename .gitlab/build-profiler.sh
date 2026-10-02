#!/usr/bin/env bash
set -e -o pipefail

shopt -s expand_aliases
if [[ -n ${BASH_ENV:-} ]]; then
    source "$BASH_ENV"
fi

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
elif [ "$artifact_mode" = "ssi-combined" ]; then
    : "${SSI_COMMON_LIBRARY:?SSI common DSO must be built first}"
    : "${SSI_EXTENSION_ARCHIVE:?The combined extension Rust archive must be built first}"
    configure_products="--enable-ddtrace-tracer --enable-ddtrace-profiling --enable-ddtrace-rust-library-split --with-ddtrace-rust-library=${SSI_COMMON_LIBRARY} --with-ddtrace-php-abi-rust-library=${SSI_EXTENSION_ARCHIVE}"
    extension_name="ddtrace"
elif [ "$artifact_mode" = "tracer-only" ]; then
    # PHP 7.0 does not support profiling, but still ships the tracer.
    configure_products="--enable-ddtrace-tracer --disable-ddtrace-profiling"
    extension_name="ddtrace"
else
    configure_products="--disable-ddtrace-tracer --enable-ddtrace-profiling"
    extension_name="datadog-profiling"
fi

# The ThinLTO release caller selects PHP/phpize first. Keep the explicit
# variant argument for Alpine and debug jobs that still switch in this script.
if [[ "$build_variant" == active ]]; then
    case "$(php -n -r 'echo (int) PHP_ZTS, ":", (int) PHP_DEBUG;')" in
        0:0) build_variant=nts ;;
        1:0) build_variant=zts ;;
        0:1) build_variant=debug ;;
        *) echo 'Unsupported active PHP ABI' >&2; exit 1 ;;
    esac
    selected_php_is_active=1
else
    selected_php_is_active=0
fi
case "$build_variant" in
    zts)
        if [[ "$selected_php_is_active" == 0 ]]; then switch-php "${PHP_VERSION}-zts"; fi
        default_output_name="${extension_name}-zts.so"
        ;;
    debug)
        if [[ "$selected_php_is_active" == 0 ]]; then switch-php "${PHP_VERSION}-debug"; fi
        default_output_name="${extension_name}-debug.so"
        ;;
    nts)
        if [[ "$selected_php_is_active" == 0 ]]; then switch-php "${PHP_VERSION}"; fi
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
# Keep the source path stable across products: Cargo keys path dependencies by
# their absolute source path, so separate per-product dirs would defeat reuse.
build_dir="/tmp/ddtrace-build-profiler-${build_variant}"
rm -rf "${build_dir}"
mkdir -p "${build_dir}/src"
# Avoid copying host build outputs into the isolated phpize build. These are
# regenerated there; copying multi-GB Cargo caches also slows local builds.
root_excludes=()
if [[ $(uname -s) == Linux ]]; then
    # BSD tar matches root-level exclude patterns against nested paths too:
    # excluding ./config.h removes zend_abstract_interface/config/config.h,
    # and ./standalone_* removes tracer/standalone_limiter.h on macOS.
    # phpize regenerates the root config headers in the copied tree.
    root_excludes=(--exclude=./config.h --exclude=./config.h.in \
        --exclude='./standalone_*' --exclude='./extensions_*' \
        --exclude='./ssi_*' --exclude='./libdatadog_php_*')
fi
tar -cf - --exclude=.git --exclude=tmp --exclude=target --exclude=target-common \
    --exclude=target_mockgen --exclude=modules --exclude=.libs \
    --exclude='*.lo' --exclude='*.o' --exclude='*.la' --exclude='*.dep' \
    --exclude=Makefile.objects \
    --exclude=Makefile.fragments --exclude=config.status --exclude=config.log \
    --exclude=config.nice --exclude=autom4te.cache --exclude=libtool \
    --exclude=./configure --exclude=./configure.ac --exclude=./run-tests.php \
    "${root_excludes[@]}" . | tar -xf - -C "${build_dir}/src"
cd "${build_dir}/src"
phpize
./configure ${configure_products}
# Make -s propagates via MAKEFLAGS. Keep libtool's own --silent setting local
# to this generated PHP Makefile: a command-line LIBTOOL= override would also
# propagate to Cargo's native sub-builds (including libunwind).
make_flags="${MAKEFLAGS:-}"
if [[ "${make_flags%% *}" =~ ^[^-]*s || " $make_flags " == *" --silent "* ]]; then
    printf '\nLIBTOOL = $(SHELL) $(top_builddir)/libtool --silent\n' >> Makefile
fi
if command -v nproc >/dev/null; then
    workers=$(nproc)
else
    workers=$(sysctl -n hw.ncpu)
fi
make -j"${MAKE_JOBS:-$workers}"
cp -v "modules/${extension_name}.so" "${output_file}"
if [[ $(uname -s) == Linux ]]; then
    objcopy --compress-debug-sections "${output_file}"
fi
