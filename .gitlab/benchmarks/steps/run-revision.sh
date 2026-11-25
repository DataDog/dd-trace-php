#!/usr/bin/env bash

set -euo pipefail

export SCENARIO=$BP_SCENARIO
unset CI DD_TRACE_ASSUME_COMPILED

if [ "$BOLT_MODE" != off ]; then
  EXTENSION_DIR=$(php -n -r 'echo ini_get("extension_dir");')
  # A previous worker may still map the installed library; replace it atomically.
  TEMP_MODULE=$(mktemp "$EXTENSION_DIR/ddtrace.XXXXXX.so")
  cp "$BOLT_DIR/$BASELINE_OR_CANDIDATE.so" "$TEMP_MODULE"
  chmod 755 "$TEMP_MODULE"
  mv -f "$TEMP_MODULE" "$EXTENSION_DIR/ddtrace.so"
  export DD_TRACE_ASSUME_COMPILED=1
fi

cd "$CODE_SRC/benchmark/"
rm -rf /tmp/logs/
mkdir -p /tmp/logs
# A baseline from before TRACER_PART or PHPBENCH_SHARD existed would run
# every part or subject.
if { [ -n "${TRACER_PART:-}" ] && ! grep -q TRACER_PART runall.sh; } ||
  { [ -n "${PHPBENCH_SHARD:-}" ] && ! grep -q PHPBENCH_SHARD runall.sh; }; then
  cp "$CI_PROJECT_DIR/benchmark/runall.sh" runall.sh
fi
taskset -c 34-47 ./runall.sh

if [ "$BOLT_MODE" != off ]; then
  # Detect future build-script changes that discard the rewritten module.
  cmp "$BOLT_DIR/$BASELINE_OR_CANDIDATE.so" "$EXTENSION_DIR/ddtrace.so"
  cmp "$BOLT_DIR/$BASELINE_OR_CANDIDATE.so" "$CODE_SRC/tmp/build_combined/modules/ddtrace.so"
fi

if [ "$SCENARIO" = profiler ]; then
  mv "$ARTIFACTS_DIR/sirun_timeline.ndjson" "$ARTIFACTS_DIR/$BASELINE_OR_CANDIDATE-sirun_timeline.ndjson"
  mv "$ARTIFACTS_DIR/sirun_exceptions.ndjson" "$ARTIFACTS_DIR/$BASELINE_OR_CANDIDATE-sirun_exceptions.ndjson"
elif [ "$SCENARIO" = tracer ]; then
  # The phpbench parts run as shards; suffix their result files -1/-2.
  suffix="${PHPBENCH_SHARD:+-$PHPBENCH_SHARD}"
  if [ -z "${TRACER_PART:-}" ] || [ "$TRACER_PART" = phpbench-opcache ]; then
    # The third CSV column is the subject; distinguish OPcache subjects.
    awk -F ',' -v OFS=',' 'NR == 1 { print } NR > 1 { $3 = $3 "-opcache"; print }' \
      "$ARTIFACTS_DIR/tracer-bench-results-opcache$suffix.csv" > "$ARTIFACTS_DIR/tracer-bench-results-opcache.tmp"
    mv "$ARTIFACTS_DIR/tracer-bench-results-opcache.tmp" "$ARTIFACTS_DIR/$BASELINE_OR_CANDIDATE-tracer-bench-results-opcache$suffix.csv"
    rm "$ARTIFACTS_DIR/tracer-bench-results-opcache$suffix.csv"
  fi
  if [ -z "${TRACER_PART:-}" ] || [ "$TRACER_PART" = phpbench ]; then
    mv "$ARTIFACTS_DIR/tracer-bench-results$suffix.csv" "$ARTIFACTS_DIR/$BASELINE_OR_CANDIDATE-tracer-bench-results$suffix.csv"
  fi
  if [ -z "${TRACER_PART:-}" ] || [ "$TRACER_PART" = tea ]; then
    mv "$ARTIFACTS_DIR/tracer-tea-bench-results.json" "$ARTIFACTS_DIR/$BASELINE_OR_CANDIDATE-tracer-tea-bench-results.json"
  fi
elif [ "$SCENARIO" = appsec ]; then
  awk -F ',' -v OFS=',' 'NR == 1 { print } NR > 1 { $3 = $3 "-appsec"; print }' \
    "$ARTIFACTS_DIR/appsec-bench-results.csv" > "$ARTIFACTS_DIR/appsec-bench-results.tmp"
  mv "$ARTIFACTS_DIR/appsec-bench-results.tmp" "$ARTIFACTS_DIR/$BASELINE_OR_CANDIDATE-appsec-bench-results.csv"
  rm "$ARTIFACTS_DIR/appsec-bench-results.csv"
fi

tar -zcf "$CI_PROJECT_DIR/$BASELINE_OR_CANDIDATE.tar.gz" /tmp/logs
rm -rf /tmp/logs
