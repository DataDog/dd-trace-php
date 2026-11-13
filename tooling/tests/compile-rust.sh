#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../.."
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
cat > "$tmp/cargo" <<'EOF'
#!/bin/sh
printf '%s\n' "$@" > "$ARGS"
printf '%s\n' "$RUSTFLAGS" > "$FLAGS"
EOF
cat > "$tmp/rustc" <<'EOF'
#!/bin/sh
echo 'host: aarch64-alpine-linux-musl'
EOF
chmod +x "$tmp/cargo" "$tmp/rustc"
export DDTRACE_CARGO="$tmp/cargo" RUSTC="$tmp/rustc" ARGS="$tmp/args" FLAGS="$tmp/flags"

check() {
  grep -Fxq -- "$1" "$ARGS"
}

SHARED=1 host_os=linux-gnu PROFILE=tracer-release sh ./compile_rust.sh --quiet -j5
check rustc
check --lib
check staticlib
check tracer-release
check -j5
grep -q -- '--cfg php_shared_build' "$FLAGS"

CARGO_CRATE_TYPE=staticlib,cdylib host_os=linux-gnu sh ./compile_rust.sh --quiet
check staticlib,cdylib
! grep -Fxq -- --profile "$ARGS"

host_os=darwin RUSTFLAGS='-Clinker-plugin-lto' sh ./compile_rust.sh --quiet
check staticlib
grep -q -- '-Clinker-plugin-lto' "$FLAGS"

host_os=linux-musl PROFILE=tracer-release CARGO_TARGET_DIR="$tmp/target" \
  sh ./compile_rust.sh --quiet --target aarch64-alpine-linux-musl
check staticlib
check -Zhost-config
check --artifact-dir
check "$tmp/target/tracer-release"
[[ $(grep -Fxc -- --target "$ARGS") == 1 ]]
grep -q -- 'target-feature=-crt-static' "$FLAGS"
echo 'Rust artifact selection checks passed.'
