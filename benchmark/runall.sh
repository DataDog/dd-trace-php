#!/usr/bin/env bash

set -exu

make_combined() {
  make BUILD_SUFFIX=combined \
    EXTRA_CONFIGURE_OPTIONS="--enable-ddtrace-tracer --enable-ddtrace-profiling --enable-ddtrace-appsec" "$@"
}

# Measure the same combined extension that is shipped to users in every scenario.
# The profiler feature is fixed at configure time, so it is enabled for every
# scenario: a build directory shared between scenarios must not lack it.
export DDTRACE_PROFILING_FEATURES=trigger_time_sample
# The benchmark platform runs a real agent: keep remote configuration it could
# deliver from changing the behaviour being measured.
export DD_REMOTE_CONFIG_ENABLED=false
cd ..
COMBINED_SO=tmp/build_combined/modules/ddtrace.so
if [ -z "${DD_TRACE_ASSUME_COMPILED:-}" ]; then
  make_combined all
else
  # Precompiled (e.g. aligned) module: fail early if it is missing, and make it
  # newer than any previously installed ddtrace.so so that the timestamp-based
  # install rule copies it instead of keeping a stale binary.
  test -f "$COMBINED_SO"
  touch "$COMBINED_SO"
fi

if [ "$SCENARIO" = "profiler" ]; then
  # Run Profiling Benchmarks
  # timeline.php needs trigger_time_sample; a module configured without it would
  # only fail inside sirun.
  php -n -d extension="$COMBINED_SO" \
    -r 'exit(function_exists("Datadog\\Profiling\\trigger_time_sample") ? 0 : 1);'
  cd profiling/

  sirun benches/timeline.json > "$ARTIFACTS_DIR/sirun_timeline.ndjson"

  sirun benches/exceptions.json > "$ARTIFACTS_DIR/sirun_exceptions.ndjson"

elif [ "$SCENARIO" = "tracer" ]; then
  # Run Trace Benchmarks
  export DD_PROFILING_ENABLED=0 DD_APPSEC_ENABLED=0
  make composer_tests_update
  make_combined benchmarks_run_dependencies

  ## Non-OPCache Benchmarks
  make_combined call_benchmarks
  cp tests/Benchmarks/reports/tracer-bench-results.csv "$ARTIFACTS_DIR/tracer-bench-results.csv"

  ## OPCache Benchmarks
  # Start from a cleared Symfony cache, like the non-OPCache run.
  make_combined benchmarks_run_dependencies
  make_combined call_benchmarks_opcache
  cp tests/Benchmarks/reports/tracer-bench-results-opcache.csv "$ARTIFACTS_DIR/tracer-bench-results-opcache.csv"

  ## Request Startup/Shutdown Benchmarks
  make benchmarks_tea
  cp tea/benchmarks/reports/tracer-tea-bench-results.json "$ARTIFACTS_DIR/tracer-tea-bench-results.json"
elif [ "$SCENARIO" = "appsec" ]; then
  # Run Appsec Benchmarks
  # AppSec itself is enabled only in the measured web servers, by
  # FrameworkBenchmarksCase; exporting DD_APPSEC_ENABLED=1 here would also turn
  # it on in the phpbench CLI processes.
  export DD_PROFILING_ENABLED=0
  make composer_tests_update
  make_combined benchmarks_run_dependencies

  # Configure AppSec only once the setup steps above have run, as the INI
  # settings written by install_appsec used to be.
  export DD_APPSEC_CLI_START_ON_RINIT=true
  export DD_APPSEC_RULES=/tmp/recommended.json
  export DD_APPSEC_LOG_FILE=/tmp/logs/appsec.log
  export DD_APPSEC_HELPER_LOG_FILE=/tmp/logs/helper.log
  cp appsec/recommended.json "$DD_APPSEC_RULES"

  ## Non-OPCache Benchmarks
  BENCHMARK_EXTRA="--group=frameworks" make_combined call_benchmarks
  cp tests/Benchmarks/reports/tracer-bench-results.csv "$ARTIFACTS_DIR/appsec-bench-results.csv"
fi
