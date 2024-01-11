#!/usr/bin/env bash

set -exu

if [ "$SCENARIO" = "profiler" ]; then
  # Run Profiling Benchmarks
  cd ../profiling/

  cargo build --release --features trigger_time_sample

  sirun benches/memory.json > "$ARTIFACTS_DIR/sirun_mem.ndjson"

  sed -i -e "s/crate-type.*$/crate-type = [\"rlib\"]/g" Cargo.toml

  cargo bench --features stack_walking_tests -- --noplot
elif [ "$SCENARIO" = "tracer" ]; then
  # Run Trace Benchmarks
  cd ..
  make composer_tests_update

  ## Non-OPCache Benchmarks
  make benchmarks
  cp tests/Benchmarks/reports/tracer-bench-results.csv "$ARTIFACTS_DIR"

  ## OPCache Benchmarks
  make benchmarks_opcache
  cp tests/Benchmarks/reports/tracer-bench-results-opcache.csv "$ARTIFACTS_DIR"

  ## Request Startup/Shutdown Benchmarks
  make benchmarks_tea
  cp tea/benchmarks/reports/tea-bench-results.json "$ARTIFACTS_DIR"
fi
