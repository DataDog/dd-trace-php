#!/usr/bin/env bash
set -e -o pipefail

set -u
prefix="$1"
thread_safety="${2:-nts}"
variant="${3:-release}"
mkdir -vp "${prefix}"
prefix="$(cd "${prefix}" && pwd)"

if [ "$thread_safety" = "zts" ]; then
    default_php_sdk_version="${PHP_VERSION}-release-zts"
    output_file="${prefix}/datadog-profiling-zts.so"
else
    default_php_sdk_version="${PHP_VERSION}"
    output_file="${prefix}/datadog-profiling.so"
fi

php_sdk_root="${PHP_SDK_ROOT:-/opt/php}"
php_sdk_version="${PROFILER_PHP_SDK_VERSION:-${default_php_sdk_version}}"
project_dir="${CI_PROJECT_DIR:-$(pwd)}"
build_suffix="profiler-${PHP_VERSION}-${thread_safety}-${variant}"
module="${project_dir}/tmp/build_${build_suffix}/modules/datadog-profiling.so"
rust_target="$(uname -m)-unknown-linux-musl"
cargo_build_flags="--config target-applies-to-host=false"
cargo_build_flags+=" --config 'host.rustflags=[\"-C\", \"target-feature=-crt-static\"]'"
cargo_build_flags+=" -Zhost-config -Ztarget-applies-to-host -Zunstable-options"
cargo_build_flags+=" -Z build-std=std,panic_abort"
cargo_build_flags+=" -Z build-std-features=llvm-libunwind,backtrace"
if [ "$(uname -m)" = aarch64 ]; then
    libc_compat_sources="$(.gitlab/prepare-rust-libc-compat.sh)"
    while IFS=$'\t' read -r libc_version libc_compat; do
        patch_name="libc_compat_${libc_version//./_}"
        cargo_build_flags+=" --config 'patch.crates-io.${patch_name}.package=\"libc\"'"
        cargo_build_flags+=" --config 'patch.crates-io.${patch_name}.path=\"${libc_compat}\"'"
    done <<< "${libc_compat_sources}"
fi
rustflags='-C target-feature=-crt-static -C linker=musl-clang'
rustflags+=' -C link-arg=/usr/lib/libunwind.a -C force-unwind-tables=yes'
cflags="${CFLAGS:-}"
cxxflags="${CXXFLAGS:-${CFLAGS:-}}"
ldflags="${LDFLAGS:-}"
runtime_library_path="${LD_LIBRARY_PATH:-}"

case "${PROFILER_SANITIZER:-none}" in
    none)
        ;;
    address)
        rustflags+=' -Zsanitizer=address -C force-frame-pointers=yes'
        rustflags+=' -C link-arg=-fsanitize=address -C link-arg=-shared-libasan'
        cflags+=" -fsanitize=address -fsanitize-address-use-after-scope"
        cflags+=" -fno-omit-frame-pointer -DZEND_TRACK_ARENA_ALLOC"
        cxxflags+=" -fsanitize=address -fsanitize-address-use-after-scope"
        cxxflags+=" -fno-omit-frame-pointer -DZEND_TRACK_ARENA_ALLOC"
        ldflags+=" -fsanitize=address -shared-libasan"
        asan_runtime="$(musl-clang -print-file-name="libclang_rt.asan-$(uname -m).so")"
        runtime_library_path="$(dirname "${asan_runtime}")${runtime_library_path:+:${runtime_library_path}}"
        ;;
    undefined)
        sanitizer_flags="-fsanitize=undefined,local-bounds"
        sanitizer_flags+=" -fno-sanitize-recover=all"
        rustflags+=" -C link-arg=-fsanitize=undefined,local-bounds"
        rustflags+=" -C link-arg=-fno-sanitize-recover=all"
        cflags+=" ${sanitizer_flags} -fno-omit-frame-pointer"
        cxxflags+=" ${sanitizer_flags} -fno-omit-frame-pointer"
        ldflags+=" ${sanitizer_flags}"
        ;;
    *)
        echo "Unknown profiler sanitizer: ${PROFILER_SANITIZER}" >&2
        exit 1
        ;;
esac

PHPRC='' \
  PATH="${php_sdk_root}/${php_sdk_version}/bin:${PATH}" \
  PHP_SDK_ROOT="${php_sdk_root}" \
  PHP_SDK_VERSION="${php_sdk_version}" \
  DDTRACE_PROFILING_FEATURES="${PROFILER_FEATURES:-}" \
  DDTRACE_PROFILING_TARGET="${rust_target}" \
  DDTRACE_PROFILING_CARGO_BUILD_FLAGS="${cargo_build_flags}" \
  RUSTC_BOOTSTRAP=1 \
  RUSTFLAGS="${rustflags} ${RUSTFLAGS:-}" \
  CFLAGS="${cflags}" \
  CXXFLAGS="${cxxflags}" \
  LDFLAGS="${ldflags}" \
  LD_LIBRARY_PATH="${runtime_library_path}" \
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
