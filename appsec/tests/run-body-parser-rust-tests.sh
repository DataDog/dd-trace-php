#!/bin/sh
set -eu

cd "$(dirname "$0")/../.."
export RUSTC_BOOTSTRAP=ddtrace_appsec_body

cargo fmt --package ddtrace-appsec-body --check
cargo clippy --package ddtrace-appsec-body --release --locked --all-targets --no-deps
cargo test --package ddtrace-appsec-body --release --locked
