#!/usr/bin/env bash

set -exu

cd ../profiling/

cargo build --release --features trigger_time_sample

sirun benches/memory.json > "$ARTIFACTS_DIR/sirun_mem.ndjson"

sed -i -e "s/crate-type.*$/crate-type = [\"rlib\"]/g" Cargo.toml

cargo bench --features stack_walking_tests -- --noplot
