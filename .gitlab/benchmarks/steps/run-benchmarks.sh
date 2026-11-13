#!/usr/bin/env bash

set -ex

# -- Candidate release

CANDIDATE_SRC="/app/candidate/"
CANDIDATE_BRANCH=$UPSTREAM_BRANCH
CANDIDATE_COMMIT_SHA=$UPSTREAM_COMMIT_SHA

GITLAB_ARTIFACTS_PATH="/go/src/github.com/DataDog/apm-reliability/dd-trace-php/"

# Clone your candidate release
git clone --recurse-submodules --branch "$CANDIDATE_BRANCH" https://github.com/DataDog/dd-trace-php/ "$CANDIDATE_SRC" && \
  cd "$CANDIDATE_SRC" && \
  git checkout "$CANDIDATE_COMMIT_SHA" && \
  git submodule update --init --recursive

if [ "$SCENARIO" = "tracer" ] || [ "$SCENARIO" = "appsec" ]; then
  echo "extension=ddtrace.so" >> /etc/php/8.2/cgi/conf.d/ddtrace.ini
  if [ "$SCENARIO" = "appsec" ]; then
    echo "extension=ddappsec.so" >> /etc/php/8.2/cgi/conf.d/ddtrace.ini
    echo "datadog.appsec.cli_start_on_rinit=true" >> /etc/php/8.2/cgi/conf.d/ddtrace.ini
    echo "datadog.appsec.helper_path=$(PHP_EXTENSION_DIR)/libddappsec-helper.so" >> /etc/php/8.2/cgi/conf.d/ddtrace.ini
    echo "datadog.appsec.rules=/tmp/recommended.json" >> /etc/php/8.2/cgi/conf.d/ddtrace.ini
    echo "datadog.appsec.helper_socket_path=/tmp/ddappsec.sock" >> /etc/php/8.2/cgi/conf.d/ddtrace.ini
    echo "datadog.appsec.helper_lock_path=/tmp/ddappsec.lock" >> /etc/php/8.2/cgi/conf.d/ddtrace.ini
    echo "datadog.appsec.log_file=/tmp/logs/appsec.log" >> /etc/php/8.2/cgi/conf.d/ddtrace.ini
    echo "datadog.appsec.helper_log_file=/tmp/logs/helper.log" >> /etc/php/8.2/cgi/conf.d/ddtrace.ini
  fi
  taskset -c 26-29 service mysql start
  taskset -c 30-33 redis-server --daemonize yes
fi

echo "127.0.0.1 mysql-integration" >> /etc/hosts
echo "127.0.0.1 redis-integration" >> /etc/hosts

echo "display_errors=stderr" >> /etc/php/8.2/cli/php.ini
echo "error_log=/tmp/logs/php-error.log" >> /etc/php/8.2/cli/php.ini
echo "display_startup_errors=1" >> /etc/php/8.2/cli/php.ini
echo "display_errors=stderr" >> /etc/php/8.2/cgi/conf.d/php.ini
echo "error_log=/tmp/logs/php-error-cgi.log" >> /etc/php/8.2/cgi/conf.d/php.ini
echo "display_startup_errors=1" >> /etc/php/8.2/cgi/conf.d/php.ini

# Allow composer to run as root
export COMPOSER_ALLOW_SUPERUSER=1

# Allow Autoloading (See Datadog/dd-trace-php:tests/bootstrap.php)
export DD_TRACE_AUTOLOAD_NO_COMPILE=true
unset CI

# Improve stability of benchmarks
export CFLAGS="-O2 -falign-functions=64"

# Ensure ddprof can run
if ! sysctl -w kernel.perf_event_paranoid=2; then
  echo -e "\e[41mERROR: Failed to set kernel.perf_event_paranoid to 2. This is required by ddprof.\e[0m"
  echo -e "\e[41mPlease check if the container is running with --privileged.\e[0m"
  exit 1
fi

# Don't filter ddprof benches away
export RUN_WEB_BENCHES_WITH_DDPROF=1

save_run_results () {
  TYPE=$1
  OUTPUT_DIR="$ARTIFACTS_DIR/$TYPE"

  # Save results to artifacts directory
  mkdir -p "$OUTPUT_DIR"
  if [ "$SCENARIO" = "profiler" ]; then
    cp $ARTIFACTS_DIR/sirun_timeline.ndjson "$OUTPUT_DIR"
    cp $ARTIFACTS_DIR/sirun_exceptions.ndjson "$OUTPUT_DIR"
  elif [ "$SCENARIO" = "tracer" ]; then
    # For the OPCache CSV results, we replace the value of the subject (third column by default) with the value of the
    # subject followed by "-opcache", to differentiate it from the non-opcache results
    awk -F ',' -v OFS=',' 'NR == 1 { print } NR > 1 { $3 = $3 "-opcache"; print }' $ARTIFACTS_DIR/tracer-bench-results-opcache.csv > $ARTIFACTS_DIR/tracer-bench-results-opcache.tmp && \
    mv $ARTIFACTS_DIR/tracer-bench-results-opcache.tmp $ARTIFACTS_DIR/tracer-bench-results-opcache.csv && \

    cp $ARTIFACTS_DIR/tracer-bench-results.csv "$OUTPUT_DIR"
    cp $ARTIFACTS_DIR/tracer-bench-results-opcache.csv "$OUTPUT_DIR"
    cp $ARTIFACTS_DIR/tracer-tea-bench-results.json "$OUTPUT_DIR"
  elif [ "$SCENARIO" = "appsec" ]; then
    # For the OPCache CSV results, we replace the value of the subject (third column by default) with the value of the
    # subject followed by "-opcache", to differentiate it from the non-opcache results
    awk -F ',' -v OFS=',' 'NR == 1 { print } NR > 1 { $3 = $3 "-appsec"; print }' $ARTIFACTS_DIR/appsec-bench-results.csv > $ARTIFACTS_DIR/appsec-bench-results.tmp && \
      mv $ARTIFACTS_DIR/appsec-bench-results.tmp "$OUTPUT_DIR/appsec-bench-results.csv"

    awk -F ',' -v OFS=',' 'NR == 1 { print } NR > 1 { $3 = $3 "-appsec-opcache"; print }' $ARTIFACTS_DIR/appsec-bench-results-opcache.csv > $ARTIFACTS_DIR/appsec-bench-results-opcache.tmp && \
      mv $ARTIFACTS_DIR/appsec-bench-results-opcache.tmp "$OUTPUT_DIR/appsec-bench-results-opcache.csv"

    cat $ARTIFACTS_DIR/appsec-tea-bench-results.json | jq '( .benchmarks[] | select(.name) | .name ) |= "AppSec_\(.)"' > "$OUTPUT_DIR/appsec-tea-bench-results.json"
  fi

  #Save all logs as artifacts
  tar -zcvf $GITLAB_ARTIFACTS_PATH/$TYPE.tar.gz /tmp/logs

  # Clean up artifacts directory before next run
  (rm "$ARTIFACTS_DIR/*.ndjson" || :) && (rm "$ARTIFACTS_DIR/*.csv" || :) && (rm "$ARTIFACTS_DIR/*.json" || :) && (rm "$ARTIFACTS_DIR/*.tmp" || :) && (rm -rf "/tmp/logs" || :) 
}

# Run benchmarks for candidate release
cd "$CANDIDATE_SRC/benchmark/"
rm -rf /tmp/logs/
mkdir -p /tmp/logs
taskset -c 34-47 ./runall.sh
save_run_results "candidate"

# Run benchmarks for baseline release
BASELINE_SRC="/app/baseline/"
BASELINE_BRANCH=$(github-find-merge-into-branch --for-repo="$UPSTREAM_PROJECT_NAME" --for-pr="$UPSTREAM_BRANCH" || :)

if [ ! -z "$BASELINE_BRANCH" ]; then
  echo $BASELINE_BRANCH > $ARTIFACTS_DIR/baseline_branch.txt

  cd "$CANDIDATE_SRC"
  BASELINE_COMMIT_SHA=$(git merge-base origin/$BASELINE_BRANCH origin/$CANDIDATE_BRANCH)

  # Clone your baseline release
  git clone --recurse-submodules --branch "$BASELINE_BRANCH" https://github.com/DataDog/dd-trace-php/ "$BASELINE_SRC" && \
    cd "$BASELINE_SRC" && \
    git checkout "$BASELINE_COMMIT_SHA" && \
    git submodule update --init --recursive

  # Run benchmarks for baseline release
  cd "$BASELINE_SRC/benchmark/"
  mkdir -p /tmp/logs
  taskset -c 34-47 ./runall.sh
  save_run_results "baseline"
fi



# Teardown MySQL
if [ "$SCENARIO" = "tracer" ] || [ "$SCENARIO" = "appsec" ]; then
  service mysql stop
fi
