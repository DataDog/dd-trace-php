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

SIDECAR_VERSION=$(cat ../VERSION) RUSTFLAGS="$RUSTFLAGS" RUSTC_BOOTSTRAP=1 "${DDTRACE_CARGO:-cargo}" build $(test "${PROFILE:-debug}" = "debug" || echo --profile "$PROFILE") "$@"
