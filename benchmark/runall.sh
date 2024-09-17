#!/usr/bin/env bash

set -exu

if [ "$SCENARIO" = "profiler" ]; then
  # Run Profiling Benchmarks
  cd ../profiling/

  cargo build --release --features trigger_time_sample

  sirun benches/timeline.json > "$ARTIFACTS_DIR/sirun_timeline.ndjson"

  sirun benches/exceptions.json > "$ARTIFACTS_DIR/sirun_exceptions.ndjson"

  sed -i -e "s/crate-type.*$/crate-type = [\"rlib\"]/g" Cargo.toml

  cargo bench --features stack_walking_tests -- --noplot
elif [ "$SCENARIO" = "tracer" ]; then
  # Run Trace Benchmarks
  cd ..
  make composer_tests_update

  ## Non-OPCache Benchmarks
  make benchmarks
  cp tests/Benchmarks/reports/tracer-bench-results.csv "$ARTIFACTS_DIR/tracer-bench-results.csv"

  ## OPCache Benchmarks
  make benchmarks_opcache
  cp tests/Benchmarks/reports/tracer-bench-results-opcache.csv "$ARTIFACTS_DIR/tracer-bench-results-opcache.csv"

  ## Request Startup/Shutdown Benchmarks
  make benchmarks_tea
  cp tea/benchmarks/reports/tracer-tea-bench-results.json "$ARTIFACTS_DIR/tracer-tea-bench-results.json"
elif [ "$SCENARIO" = "appsec" ]; then
  # Run Appsec Benchmarks
  cd ..
  make composer_tests_update
  make benchmarks_run_dependencies
  make install_appsec

  ## Non-OPCache Benchmarks
  BENCHMARK_EXTRA="--group=frameworks" make call_benchmarks
  cp tests/Benchmarks/reports/tracer-bench-results.csv "$ARTIFACTS_DIR/appsec-bench-results.csv"

  [ -f /tmp/appsec.log ] && cat /tmp/appsec.log && rm /tmp/appsec.log
  [ -f /tmp/helper.log ] && cat /tmp/helper.log && rm /tmp/helper.log

  make delete_ini
fi
