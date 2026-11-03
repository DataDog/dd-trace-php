#!/bin/sh
set -eu

cd "$(dirname "$0")/../.."
export RUSTC_BOOTSTRAP=ddtrace_appsec_body
manifest=components-rs/appsec_body/Cargo.toml

cargo fmt --manifest-path "$manifest" --check
cargo clippy --manifest-path "$manifest" --release --locked --all-targets --no-deps
cargo test --manifest-path "$manifest" --release --locked
