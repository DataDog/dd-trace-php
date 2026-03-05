#!/usr/bin/env bash
set -e -o pipefail

MAKE_JOBS=${MAKE_JOBS:-$(nproc)}

mkdir -p appsec_$(uname -m)

git config --global --add safe.directory '*'

cd appsec/helper-rust

export CARGO_TARGET_DIR=/tmp/cargo-target
RUST_TARGET=$(uname -m)-unknown-linux-musl

# Build using nightly toolchain with unstable features
# -Z build-std: Rebuild std library for musl
# -Z build-std-features=llvm-libunwind: Use LLVM libunwind instead of libgcc_s
cargo +nightly-"$RUST_TARGET" build \
    --release \
    -Zhost-config \
    -Ztarget-applies-to-host \
    --target "$RUST_TARGET"

# Remove musl libc dependency using patchelf (makes binary work on both musl and glibc)
BINARY_PATH="/tmp/cargo-target/$RUST_TARGET/release/libddappsec_helper_rust.so"
ARCH=$(uname -m)
if [ "$ARCH" = "x86_64" ]; then
    patchelf --remove-needed libc.musl-x86_64.so.1 "$BINARY_PATH" 2>/dev/null || true
elif [ "$ARCH" = "aarch64" ]; then
    patchelf --remove-needed libc.musl-aarch64.so.1 "$BINARY_PATH" 2>/dev/null || true
fi

# Copy to output
cp -v "$BINARY_PATH" "../../appsec_$(uname -m)/libddappsec-helper-rust.so"

# Run tests
cargo +nightly-"$RUST_TARGET" test \
    --release \
    -Zhost-config \
    -Ztarget-applies-to-host \
    --target "$RUST_TARGET"
