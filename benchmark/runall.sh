#!/usr/bin/env bash

set -exu

if [ "$SCENARIO" = "profiler" ]; then
  # Run Profiling Benchmarks
  revision=$(git -C .. rev-parse HEAD)
  profiler_artifacts="${PROFILER_BENCHMARK_ARTIFACTS:?}/${revision}"
  release_profiler="${profiler_artifacts}/release/datadog-profiling.so"
  sampling_profiler="${profiler_artifacts}/sampling/datadog-profiling.so"
  rust_benchmark="${profiler_artifacts}/cargo/stack-walking"
  for artifact in "$release_profiler" "$sampling_profiler" "$rust_benchmark"; do
    if [ ! -f "$artifact" ]; then
      echo "Missing portable profiler benchmark artifact: $artifact" >&2
      exit 1
    fi
  done

  cd ../profiling/
  profiler_link="$PWD/../tmp/build_profiler/modules/datadog-profiling.so"
  mkdir -p "$(dirname "$profiler_link")"
  ln -sfn "$sampling_profiler" "$profiler_link"
  sirun benches/timeline.json > "$ARTIFACTS_DIR/sirun_timeline.ndjson"
  ln -sfn "$release_profiler" "$profiler_link"
  sirun benches/exceptions.json > "$ARTIFACTS_DIR/sirun_exceptions.ndjson"
  CARGO_TARGET_DIR="$PWD/../target" "$rust_benchmark" --bench --noplot
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

  make delete_ini
fi
