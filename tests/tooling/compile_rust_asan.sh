#!/bin/sh
# Exercise ASan host/target compiler isolation without a Rust toolchain.
set -eu

root=$(CDPATH= cd "$(dirname "$0")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
trap 'exit 1' HUP INT TERM

cat > "$work/compiler" <<'EOF'
#!/bin/sh
set -eu
# Check that compiler-wrapper arguments survive too.
test "$1" = --sentinel
shift
if test "${1:-}" = -v; then
  echo 'clang test compiler' >&2
else
  printf '%s\n' "$@"
fi
EOF

cat > "$work/cargo" <<'EOF'
#!/bin/sh
set -eu
case "$*" in
  *host.rustflags*) echo 'Host Rust flags must not gain an ASan runtime' >&2; exit 1 ;;
esac
case "$RUSTFLAGS" in
  *link-arg=-fsanitize=address*) ;;
  *) exit 1 ;;
esac

# Cargo supplies empty encoded flags to host build scripts with --target,
# even when HOST and TARGET are identical. Other host flags are harmless.
for flags in '' '--cfg=host_tool' '-Zsanitizer=address' '-Clink-arg=-fsanitize=address'; do
  CARGO_ENCODED_RUSTFLAGS="$flags" $CC \
    -fsanitize=address 'argument with spaces' -fno-omit-frame-pointer > actual
  case "$flags" in
    -Zsanitizer=address|-Clink-arg=-fsanitize=address)
      printf '%s\n' -fsanitize=address > expected ;;
    *) : > expected ;;
  esac
  printf '%s\n' 'argument with spaces' -fno-omit-frame-pointer >> expected
  diff -u expected actual
done

# CC must retain a stable cache identity between otherwise identical runs.
if test -f previous-cc; then
  test "$(cat previous-cc)" = "$CC"
fi
printf '%s\n' "$CC" > previous-cc
EOF
chmod +x "$work/compiler" "$work/cargo"
printf '%s\n' test > "$work/VERSION"

cd "$work"
export COMPILE_ASAN=1 CC="$work/compiler --sentinel"
export DDTRACE_CARGO="$work/cargo" CARGO_TARGET_DIR="$work/target"
export CARGO_FEATURES=--no-default-features host_os=linux-gnu
# Ignore the caller's Rust flags so the test is deterministic.
export RUSTFLAGS=
sh "$root/compile_rust.sh" --quiet --target native
sh "$root/compile_rust.sh" --quiet --target=native
sh "$root/compile_rust.sh" --quiet
printf '%s\n' 'ASan compiler isolation: PASS'
