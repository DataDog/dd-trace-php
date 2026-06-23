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
esac

# GCC < 9 doesn't support -fuse-ld=lld (emitted by libdd-otel-thread-ctx-ffi/build.rs).
# Intercept CC calls and replace -fuse-ld=lld with -B<dir> where <dir>/ld -> ld.lld.
_gcc_major=$(cc -dumpversion 2>/dev/null | cut -d. -f1)
if [ -n "${_gcc_major}" ] && [ "${_gcc_major:-99}" -lt 9 ] 2>/dev/null; then
    _sysroot=$(rustc --print sysroot 2>/dev/null)
    _tgt=$(rustc -vV 2>/dev/null | sed -n 's/^host: //p')
    _lld="${_sysroot}/lib/rustlib/${_tgt}/bin/gcc-ld/ld.lld"
    if [ -x "${_lld}" ]; then
        _wd=$(mktemp -d)
        ln -sf "${_lld}" "${_wd}/ld"
        _real_cc=$(command -v cc)
        cat > "${_wd}/cc" << EOF
#!/bin/sh
_a=
for _x in "\$@"; do
    case "\$_x" in
        -fuse-ld=lld) _a="\$_a -B${_wd}" ;;
        *) _a="\$_a \$_x" ;;
    esac
done
exec ${_real_cc} \$_a
EOF
        chmod +x "${_wd}/cc"
        export PATH="${_wd}:${PATH}"
    fi
fi

set -x

if test -n "$COMPILE_ASAN"; then
  # We need -lresolv due to https://github.com/llvm/llvm-project/issues/59007
  export LDFLAGS="-fsanitize=address $(if cc -v 2>&1 | grep -q clang; then echo "-shared-libsan -lresolv"; fi)"
  export CFLAGS="$LDFLAGS -fno-omit-frame-pointer" # the cc buildtools will only pick up CFLAGS it seems
fi

SIDECAR_VERSION=$(cat ../VERSION) RUSTFLAGS="$RUSTFLAGS" RUSTC_BOOTSTRAP=1 "${DDTRACE_CARGO:-cargo}" build $(test "${PROFILE:-debug}" = "debug" || echo --profile "$PROFILE") "$@"
