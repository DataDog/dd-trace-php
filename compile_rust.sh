#!/bin/sh

# These flags configure the PHP extension's C build. Letting them reach Cargo
# also applies them to unrelated native dependencies compiled by build scripts.
unset CFLAGS CXXFLAGS CPPFLAGS LDFLAGS

RUSTFLAGS="${RUSTFLAGS:-} --cfg tokio_unstable"

if test -n "$SHARED"; then
  RUSTFLAGS="$RUSTFLAGS --cfg php_shared_build"
fi

case "${host_os}" in
  darwin*)
    RUSTFLAGS="$RUSTFLAGS -Clink-arg=-undefined -Clink-arg=dynamic_lookup";
    ;;
  *musl*)
    rust_linker=${CC:-cc}
    RUSTFLAGS="$RUSTFLAGS -C target-feature=-crt-static -C linker=$rust_linker";
    ;;
esac

set -x

if test -n "$COMPILE_ASAN"; then
  # We need -lresolv due to https://github.com/llvm/llvm-project/issues/59007
  export LDFLAGS="-fsanitize=address $(if ${CC:-cc} -v 2>&1 | grep -q clang; then echo "-shared-libsan -lresolv"; fi)"
  export CFLAGS="$LDFLAGS -fno-omit-frame-pointer" # the cc buildtools will only pick up CFLAGS it seems
  # rustc's own link step doesn't read LDFLAGS, so build-script/test binaries
  # that link ASan-instrumented C objects (e.g. libddwaf-sys) end up with
  # undefined __asan_* symbols unless we also tell rustc to link the runtime.
  RUSTFLAGS="$RUSTFLAGS -Clink-arg=-fsanitize=address"
fi

if test "${PROFILE:-debug}" = "debug"; then
  set -- build ${CARGO_FEATURES:---features tracer} "$@"
else
  set -- build ${CARGO_FEATURES:---features tracer} --profile "$PROFILE" "$@"
fi

case "${host_os}" in
  *musl*)
    # Bindgen's build script needs dynamic CRT to load libclang. Configure its
    # host rustflags separately from the target's (whatever it is)
    # Stable Cargo needs bootstrap enabled for the host-config and artifact-dir
    # options below. This does not affect non-musl builds.
    export RUSTC_BOOTSTRAP=1
    target=$("${RUSTC:-rustc}" -vV | sed -n 's/^host: //p')
    artifact_dir="${CARGO_TARGET_DIR:-target}/${PROFILE:-debug}"
    set -- --config target-applies-to-host=false \
      --config 'host.rustflags=["-C", "target-feature=-crt-static"]' \
      "$@" -Zhost-config -Ztarget-applies-to-host -Zunstable-options \
      --target "$target" --artifact-dir "$artifact_dir"
    ;;
esac

if test -n "$RUST_TOOLCHAIN"; then
  set -- "+$RUST_TOOLCHAIN" "$@"
fi

SIDECAR_VERSION=$(cat VERSION) RUSTFLAGS="$RUSTFLAGS" "${DDTRACE_CARGO:-cargo}" "$@"
