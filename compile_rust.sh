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

case " $* " in
  *" --quiet "*|*" -q "*) ;;
  *) set -x ;;
esac

if test -n "$COMPILE_ASAN"; then
  # We need -lresolv due to https://github.com/llvm/llvm-project/issues/59007
  export LDFLAGS="-fsanitize=address $(if ${CC:-cc} -v 2>&1 | grep -q clang; then echo "-shared-libsan -lresolv"; fi)"
  export CFLAGS="$LDFLAGS -fno-omit-frame-pointer" # the cc buildtools will only pick up CFLAGS it seems
  # Rust link steps do not read LDFLAGS; link the runtime wherever Cargo
  # applies these Rust flags, matching the native instrumentation below.
  RUSTFLAGS="$RUSTFLAGS -Clink-arg=-fsanitize=address"
  # With an explicit target, Cargo excludes host tools from RUSTFLAGS, but
  # global CFLAGS still reach their native dependencies. Keep those objects
  # unsanitized too: adding an ASan runtime to all host crates also adds it to
  # proc-macro DSOs, which cannot load safely into an unsanitized rustc.
  # HOST_CFLAGS cannot distinguish these builds when the target equals HOST.
  # Cargo does distinguish them in each build script's encoded Rust flags.
  export DDTRACE_ASAN_CC="${CC:-cc}"
  # Keep CC stable across invocations so cc-rs can reuse native artifacts.
  # Compiler or wrapper changes must still change CC's cache identity.
  compiler_key=$({ printf '%s' "$DDTRACE_ASAN_CC"; cksum "$0"; } | cksum | cut -d ' ' -f 1)
  asan_cc_dir="${CARGO_TARGET_DIR:-target}/ddtrace-asan-cc/$compiler_key"
  mkdir -p "$asan_cc_dir" || exit 1
  asan_cc="$(CDPATH= cd "$asan_cc_dir" && pwd)/cc"
  asan_cc_tmp=$(mktemp "$asan_cc_dir/cc.XXXXXX") || exit 1
  cat > "$asan_cc_tmp" <<'EOF'
#!/bin/sh
case "${CARGO_ENCODED_RUSTFLAGS:-}" in
  *sanitizer=address*|*fsanitize=address*) ;;
  *)
    # Preserve argument boundaries while removing instrumentation from host C.
    count=$#
    while test "$count" -gt 0; do
      arg=$1
      shift
      case "$arg" in
        -fsanitize=address) ;;
        *) set -- "$@" "$arg" ;;
      esac
      count=$((count - 1))
    done
    ;;
esac
# CC may include compiler-wrapper arguments, just as in the invocations above.
exec $DDTRACE_ASAN_CC "$@"
EOF
  chmod +x "$asan_cc_tmp" && mv -f "$asan_cc_tmp" "$asan_cc" || exit 1
  export CC="$asan_cc"
fi

cargo_command=build
case "${host_os}:${RUSTFLAGS}" in
  darwin*:*linker-plugin-lto*)
    # Apple's ld rejects Rust's -plugin-opt arguments when Cargo also links the
    # crate's cdylib. The PHP extension needs only the Rust staticlib here.
    cargo_command=rustc
    set -- --lib --crate-type staticlib "$@"
    ;;
esac
if test "${PROFILE:-debug}" = "debug"; then
  set -- "$cargo_command" ${CARGO_FEATURES:---features tracer,tracer-runtime} "$@"
else
  set -- "$cargo_command" ${CARGO_FEATURES:---features tracer,tracer-runtime} --profile "$PROFILE" "$@"
fi

case "${host_os}" in
  *musl*)
    # Build scripts using bindgen need dynamic musl linkage to dlopen libclang.
    # Target RUSTFLAGS already disables crt-static. With --target, host build
    # scripts do not inherit those flags, so disable it in host.rustflags too.
    # Stable Cargo needs bootstrap for host-config, target-applies-to-host and
    # artifact-dir, plus build-std in the portable musl library build.
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
