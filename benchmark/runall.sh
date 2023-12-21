#!/usr/bin/env bash

set -exu

# Run Profiling Benchmarks
cd ../profiling/

cargo build --release --features trigger_time_sample

sirun benches/memory.json > "$ARTIFACTS_DIR/sirun_mem.ndjson"

sed -i -e "s/crate-type.*$/crate-type = [\"rlib\"]/g" Cargo.toml

cargo bench --features stack_walking_tests -- --noplot

# Run Trace Benchmarks
cd ..
## Non-OPCache Benchmarks > tests/Benchmarks/results.csv
make benchmarks
cp tests/Benchmarks/tracer-bench-results.csv "$ARTIFACTS_DIR"
## OPCache Benchmarks > tests/Benchmarks/results-opcache.csv
make benchmarks_opcache
cp tests/Benchmarks/tracer-bench-results-opcache.csv "$ARTIFACTS_DIR"
