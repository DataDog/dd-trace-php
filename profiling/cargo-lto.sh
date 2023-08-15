#!/bin/sh

set -eu

# These are needed for cross-language LTO
AR="$(command -v llvm-ar)"
CC="$(command -v clang)"
RANLIB="$(command -v llvm-ranlib)"
export AR
export CC
export CFLAGS="-flto=thin"
export RANLIB

RUSTFLAGS="-Clinker-plugin-lto -C linker=clang -C link-arg=-fuse-ld=lld" cargo "$@"
