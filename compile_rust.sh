#!/bin/sh
cd components-rs

RUSTFLAGS="${RUSTFLAGS:-} --cfg tokio_unstable"
if if test -z "${host_os:-}"; then case "${host_os}" in linux*) true;; *) false; esac else test "$(uname -s)" = "Linux"; fi then
  RUSTFLAGS="$RUSTFLAGS --cfg tokio_taskdump"
fi
if test -n "$SHARED"; then
  RUSTFLAGS="$RUSTFLAGS --cfg php_shared_build"
fi

case "${host_os}" in
  darwin*)
    RUSTFLAGS="$RUSTFLAGS -Clink-arg=-undefined -Clink-arg=dynamic_lookup";
    ;;
esac

set -x

if test -n "$COMPILE_ASAN"; then
  # We need -lresolv due to https://github.com/llvm/llvm-project/issues/59007
  export LDFLAGS="-fsanitize=address $(if cc -v 2>&1 | grep -q clang; then echo "-shared-libsan -lresolv"; fi)"
  export CFLAGS="$LDFLAGS -fno-omit-frame-pointer" # the cc buildtools will only pick up CFLAGS it seems
fi

SIDECAR_VERSION=$(cat -s ../VERSION) RUSTFLAGS="$RUSTFLAGS" RUSTC_BOOTSTRAP=1 "${DDTRACE_CARGO:-cargo}" build $(test "${PROFILE:-debug}" = "debug" || echo --profile "$PROFILE") "$@"
