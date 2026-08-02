#!/bin/sh
cd components-rs

has_glibc_compat=0
features_argument_follows=0
for argument in "$@"; do
  if test "$features_argument_follows" = 1; then
    features=$argument
    features_argument_follows=0
  else
    case "$argument" in
      --features)
        features_argument_follows=1
        continue
        ;;
      --features=*)
        features=${argument#--features=}
        ;;
      *)
        continue
        ;;
    esac
  fi

  old_ifs=$IFS
  IFS=', '
  for feature in $features; do
    if test "$feature" = glibc-compat; then
      has_glibc_compat=1
      break
    fi
  done
  IFS=$old_ifs
done

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
  # rustc's own link step doesn't read LDFLAGS, so build-script/test binaries
  # that link ASan-instrumented C objects (e.g. libddwaf-sys) end up with
  # undefined __asan_* symbols unless we also tell rustc to link the runtime.
  RUSTFLAGS="$RUSTFLAGS -Clink-arg=-fsanitize=address"
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
    set -- --config target-applies-to-host=false \
      --config 'host.rustflags=["-C", "target-feature=-crt-static"]' \
      "$@" -Zhost-config -Ztarget-applies-to-host -Zunstable-options \
      --target "$target" --artifact-dir "$artifact_dir"
    ;;
esac

if test -n "$RUST_TOOLCHAIN"; then
  set -- "+$RUST_TOOLCHAIN" "$@"
fi

SIDECAR_VERSION=$(cat ../VERSION) RUSTFLAGS="$RUSTFLAGS" RUSTC_BOOTSTRAP=1 "${DDTRACE_CARGO:-cargo}" "$@"
cargo_status=$?
if test "$cargo_status" -ne 0; then
  exit "$cargo_status"
fi

case "${host_os}" in
  *musl*)
    if test "$has_glibc_compat" = 1; then
      library="$artifact_dir/libdatadog_php.so"
      needed_libraries=$(patchelf --print-needed "$library") || exit $?
      for needed in $needed_libraries; do
        case "$needed" in
          libc.musl-*.so.1|libc.so)
            patchelf --remove-needed "$needed" "$library" || exit $?
            ;;
        esac
      done

      # The library is built against musl but loaded into (or exec'd by the
      # loader of) processes that may use either libc, so it cannot keep the
      # musl DT_NEEDED. Replace it with the glibc names of the same libraries:
      # musl's loader resolves every "libc.*", "libm.*", "libdl.*",
      # "libpthread.*" and "librt.*" DT_NEEDED to the implementation itself
      # without touching the filesystem (ldso/dynlink.c, "Catch and block
      # attempts to reload the implementation itself"), so these entries are
      # correct on musl hosts too. Our references to them are unversioned --
      # that is the whole point of building against musl -- so no
      # .gnu.version_r dependency on a particular glibc release is created.
      #
      # This also keeps the library from ending up with no DT_NEEDED at all:
      # since glibc 2.35, ld.so re-execs an object that has neither DT_NEEDED
      # entries nor a PT_INTERP instead of loading it (rtld_chain_load() in
      # elf/rtld.c), which crashes the sidecar's direct spawn -- there the
      # library _is_ the program passed to ld.so (see spawn_worker's
      # SpawnMethod::Direct and components-rs/build.rs's ELF entry point).
      #
      # These entries are what makes the sidecar's direct spawn and the SSI
      # loader's dlopen() work without preloading anything: ld.so/musl's ldso
      # resolve every undefined symbol from them at load time.
      for needed in libc.so.6 libm.so.6 libdl.so.2 libpthread.so.0 librt.so.1; do
        patchelf --add-needed "$needed" "$library" || exit $?
      done

      remaining_needed=$(patchelf --print-needed "$library" | sort | tr '\n' ' ') || exit $?
      expected_needed='libc.so.6 libdl.so.2 libm.so.6 libpthread.so.0 librt.so.1 '
      if test "$remaining_needed" != "$expected_needed"; then
        echo "$library has unexpected shared library dependencies:" >&2
        echo "  expected: $expected_needed" >&2
        echo "  actual:   $remaining_needed" >&2
        exit 1
      fi
    fi
    ;;
esac
