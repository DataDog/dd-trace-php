#!/bin/bash

PROFILING_DIR=../profiling/
RUST_VERSION=$(grep "channel" ${PROFILING_DIR}rust-toolchain.toml | sed -E 's/.*"([^"]+)".*/\1/')

curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs -o rustup.sh
sh rustup.sh -y --default-toolchain ${RUST_VERSION} --profile minimal
source "$HOME/.cargo/env"
