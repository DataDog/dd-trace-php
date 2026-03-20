#!/bin/sh
cd components-rs

RUSTFLAGS="${RUSTFLAGS:-} --cfg tokio_unstable"

case "${host_os}" in
  darwin*)
    RUSTFLAGS="$RUSTFLAGS -Clink-arg=-undefined -Clink-arg=dynamic_lookup";
    ;;
esac

set -ex

if test -n "$COMPILE_ASAN"; then
  # We need -lresolv due to https://github.com/llvm/llvm-project/issues/59007
  export LDFLAGS="-fsanitize=address $(if cc -v 2>&1 | grep -q clang; then echo "-shared-libsan -lresolv"; fi)"
  export CFLAGS="$LDFLAGS -fno-omit-frame-pointer" # the cc buildtools will only pick up CFLAGS it seems
fi

# Choose the cargo profile.
if test "${PROFILE:-debug}" = "debug"; then
  CARGO_PROFILE_ARG=""
else
  CARGO_PROFILE_ARG="--profile $PROFILE"
fi

CARGO_TARGET_DIR="${CARGO_TARGET_DIR:-target}"

# Sidecar-specific RUSTFLAGS.
# - musl: disable static CRT so dlopen works for loading the AppSec helper.
# - macOS: override fat LTO (from tracer-release) to thin LTO, because the
#   Xcode system linker ships an older LLVM that cannot parse the bitcode
#   produced by the Rust toolchain's fat-LTO mode.
SIDECAR_RUSTFLAGS="$RUSTFLAGS"
case "${host_os}" in
  *musl*)
    SIDECAR_RUSTFLAGS="$SIDECAR_RUSTFLAGS -C target-feature=-crt-static"
    ;;
  darwin*)
    SIDECAR_RUSTFLAGS="$SIDECAR_RUSTFLAGS -C lto=thin"
    ;;
esac

SIDECAR_VERSION=$(cat ../VERSION) RUSTFLAGS="$RUSTFLAGS" RUSTC_BOOTSTRAP=1 \
  "${DDTRACE_CARGO:-cargo}" build -p ddtrace-php $CARGO_PROFILE_ARG "$@"

if test -n "$COMPILE_ASAN"; then
  SIDECAR_RUSTFLAGS="$SIDECAR_RUSTFLAGS -Clink-arg=-fsanitize=address"
fi

SIDECAR_VERSION=$(cat ../VERSION) RUSTFLAGS="$SIDECAR_RUSTFLAGS" RUSTC_BOOTSTRAP=1 \
  "${DDTRACE_CARGO:-cargo}" build -p datadog-ipc-helper $CARGO_PROFILE_ARG "$@"

# Place datadog-ipc-helper next to where ddtrace.so will be installed so that
# find_sidecar_binary() can locate it via dladdr at runtime.
# Only do this when CARGO_TARGET_DIR is an absolute path (test/cmake builds).
# Distribution builds (build-sidecar.sh) use a relative target dir and don't
# need the binary placed in modules/.
case "$CARGO_TARGET_DIR" in
  /*)
    _ipc_profile="${PROFILE:-debug}"
    _ipc_src="${CARGO_TARGET_DIR}/${_ipc_profile}/datadog-ipc-helper"
    _ipc_dst="$(dirname "${CARGO_TARGET_DIR%/}")/modules/datadog-ipc-helper"
    mkdir -p "$(dirname "$_ipc_dst")"
    cp "$_ipc_src" "$_ipc_dst"
    ;;
esac

