#!/bin/sh
cd components-rs

RUSTFLAGS="${RUSTFLAGS:-} --cfg tokio_unstable"

if test -n "$SHARED"; then
  RUSTFLAGS="$RUSTFLAGS --cfg php_shared_build"
fi

case "${host_os}" in
  darwin*)
    RUSTFLAGS="$RUSTFLAGS -Clink-arg=-undefined -Clink-arg=dynamic_lookup";
    ;;
  *musl*)
    RUSTFLAGS="$RUSTFLAGS -C target-feature=-crt-static";
    ;;
esac

set -x

if test -n "$COMPILE_ASAN"; then
  # We need -lresolv due to https://github.com/llvm/llvm-project/issues/59007
  export LDFLAGS="-fsanitize=address $(if cc -v 2>&1 | grep -q clang; then echo "-shared-libsan -lresolv"; fi)"
  export CFLAGS="$LDFLAGS -fno-omit-frame-pointer" # the cc buildtools will only pick up CFLAGS it seems
fi

if test "${PROFILE:-debug}" = "debug"; then
  set -- build "$@"
else
  set -- build --profile "$PROFILE" "$@"
fi

case "${host_os}" in
  *musl*)
    # Bindgen's build script needs dynamic CRT to load libclang. Configure its
    # host rustflags separately from the target's (whatever it is)
    target=$("${RUSTC:-rustc}" -vV | sed -n 's/^host: //p')
    artifact_dir="${CARGO_TARGET_DIR:-../target}/${PROFILE:-debug}"
    set -- -Zhost-config -Ztarget-applies-to-host -Zunstable-options \
      --config target-applies-to-host=false \
      --config 'host.rustflags=["-C", "target-feature=-crt-static"]' \
      "$@" --target "$target" --artifact-dir "$artifact_dir"
    ;;
esac

SIDECAR_VERSION=$(cat ../VERSION) RUSTFLAGS="$RUSTFLAGS" RUSTC_BOOTSTRAP=1 "${DDTRACE_CARGO:-cargo}" "$@"
