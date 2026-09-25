#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}

set -u

build_kind="${1:-portable}"
architecture="$(uname -m)"
mkdir -p "extensions_${architecture}" "standalone_${architecture}"

mockgen="${CI_PROJECT_DIR}/php_sidecar_mockgen_${architecture}"
configure_options="--enable-ddtrace-rust-library-split --with-ddtrace-sidecar-mockgen=${mockgen}"

build_portable_variant() {
  sdk="$1"
  output_suffix="$2"

  rm -rf tmp/build_extension
  PHPRC='' \
    PATH="/opt/php/${sdk}/bin:${PATH}" \
    PHP_SDK_VERSION="${sdk}" \
    EXTRA_CFLAGS=-DCXA_THREAD_ATEXIT_WRAPPER=1 \
    EXTRA_CONFIGURE_OPTIONS="${configure_options}" \
    make -j "${MAKE_JOBS}" \
      "${CI_PROJECT_DIR}/tmp/build_extension/modules/ddtrace.a"

  objcopy --compress-debug-sections \
    tmp/build_extension/modules/ddtrace.so \
    "standalone_${architecture}/ddtrace-${ABI_NO}${output_suffix}.so"
  cp -v tmp/build_extension/modules/ddtrace.a \
    "extensions_${architecture}/ddtrace-${ABI_NO}${output_suffix}.a"
}

if [ "${build_kind}" = "debug" ]; then
  # A debug Zend ABI cannot consume the release extension, but it can still
  # use the portable SDK and sidecar toolchain.
  build_portable_variant "${PHP_VERSION}-debug" "-debug"
  cp -v tmp/build_extension/ddtrace-fat.ldflags \
    "ddtrace_${architecture}-fat.ldflags"
  cp -v tmp/build_extension/ddtrace-fat.sym \
    "ddtrace_${architecture}-fat.sym"
  .gitlab/link-tracing-extension.sh
  exit 0
fi

build_portable_variant "${PHP_VERSION}" ""
cp -v tmp/build_extension/ddtrace-fat.ldflags \
  "ddtrace_${architecture}-fat.ldflags"
cp -v tmp/build_extension/ddtrace-fat.sym \
  "ddtrace_${architecture}-fat.sym"
build_portable_variant "${PHP_VERSION}-release-zts" "-zts"
.gitlab/link-tracing-extension.sh
