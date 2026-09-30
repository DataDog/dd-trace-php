#!/usr/bin/env bash
set -e -o pipefail

set -u
prefix="$1"
thread_safety="${2:-nts}"
mkdir -vp "${prefix}"
prefix="$(cd "${prefix}" && pwd)"

if [ "$thread_safety" = "zts" ]; then
    php_sdk_version="${PHP_VERSION}-release-zts"
    output_file="${prefix}/datadog-profiling-zts.so"
else
    php_sdk_version="${PHP_VERSION}"
    output_file="${prefix}/datadog-profiling.so"
fi

build_suffix="profiler-${thread_safety}"
module="${CI_PROJECT_DIR}/tmp/build_${build_suffix}/modules/datadog-profiling.so"
rust_target="$(uname -m)-unknown-linux-musl"
cargo_build_flags="--config target-applies-to-host=false"
cargo_build_flags+=" --config 'host.rustflags=[\"-C\", \"target-feature=-crt-static\"]'"
cargo_build_flags+=" -Zhost-config -Ztarget-applies-to-host -Zunstable-options"
cargo_build_flags+=" -Z build-std=std,panic_abort"
cargo_build_flags+=" -Z build-std-features=llvm-libunwind,backtrace"
PHPRC='' \
  PATH="/opt/php/${php_sdk_version}/bin:${PATH}" \
  PHP_SDK_VERSION="${php_sdk_version}" \
  DDTRACE_PROFILING_TARGET="${rust_target}" \
  DDTRACE_PROFILING_CARGO_BUILD_FLAGS="${cargo_build_flags}" \
  RUSTC_BOOTSTRAP=1 \
  RUSTFLAGS='-C target-feature=-crt-static -C linker=musl-clang -C link-arg=/usr/lib/libunwind.a -C force-unwind-tables=yes' \
  make -j"$(nproc)" \
    BUILD_SUFFIX="${build_suffix}" \
    PROFILING=1 \
    EXTRA_CONFIGURE_OPTIONS="--disable-ddtrace-tracer --enable-ddtrace-profiling" \
    "${module}"

if readelf --version-info "${module}" | grep GLIBC_ >/dev/null; then
    echo "${module} is not portable: found a GLIBC symbol version" >&2
    exit 1
fi
cp -v "${module}" "${output_file}"
objcopy --compress-debug-sections "${output_file}"
