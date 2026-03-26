#!/usr/bin/env bash
# Verify that libdatadog-php-profiling-shm contains no panic paths.
#
# Strategy:
#   1. Build the audit cdylib with panic=abort + fat LTO + -Z build-std=core.
#      Rebuilding core from source puts it in the same LTO unit, so unreachable
#      panic paths in core are eliminated alongside user code.
#   2. Run `nm --demangle` on the resulting .so/.dylib and fail if any
#      core::panicking symbols survived.
#
# Requires: RUSTC_BOOTSTRAP=1, a Rust src component (rustup component add rust-src).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

TARGET=$(rustc -vV | awk '/^host:/{print $2}')

echo "==> Building panic-audit cdylib for $TARGET ..."
RUSTC_BOOTSTRAP=1 cargo build \
    -Z build-std=core \
    --target "$TARGET" \
    --profile panic-audit \
    2>&1

LIB=$(find "../../target/$TARGET/panic-audit" -maxdepth 1 \
    \( -name "*.so" -o -name "*.dylib" \) \
    -name "libprofiling_shm_panic_audit*" \
    | head -1)

if [[ -z "$LIB" ]]; then
    echo "ERROR: could not find audit cdylib under ../../target/$TARGET/panic-audit"
    exit 2
fi

echo "==> Checking for panic symbols in $LIB ..."

# nm --demangle decodes Rust symbol names; grep for anything in core::panicking.
PANIC_SYMS=$(nm --demangle "$LIB" 2>/dev/null \
    | grep -E 'panicking|begin_unwind|panic_fmt' || true)

if [[ -n "$PANIC_SYMS" ]]; then
    echo "FAIL: panic symbols found in $LIB:"
    echo "$PANIC_SYMS"
    exit 1
fi

echo "PASS: no panic symbols in $LIB"
