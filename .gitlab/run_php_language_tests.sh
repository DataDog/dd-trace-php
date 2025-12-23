#!/usr/bin/env bash
set -eo pipefail

# Helper to parse version strings for comparison
function version { echo "$@" | awk -F. '{ printf("%d%03d%03d%03d\n", $1,$2,$3,$4); }'; }

# Helper to detect retriable infrastructure errors
function is_retriable_error() {
  local output="$1"
  if echo "$output" | grep -q "Failed to accept connection from worker"; then
    return 0
  fi
  if echo "$output" | grep -q "stream_socket_accept(): Accept failed: Connection timed out"; then
    return 0
  fi
  return 1
}

sudo rm -f /opt/php/debug/conf.d/memcached.ini
sudo rm -f /opt/php/debug/conf.d/rdkafka.ini
if [[ ! "${XFAIL_LIST:-none}" == "none" ]]; then
  cp "${XFAIL_LIST}" /usr/local/src/php/xfail_tests.list
  (
    cd /usr/local/src/php
    (cat xfail_tests.list; grep -lrFx zend_test.observer.enabled=0 .) | xargs -n 1 -I{} find {} -name "*.phpt" -delete || true
  )
fi

cd /usr/local/src/php
mkdir -p /tmp/artifacts/tests
# replace all hardcoded object ids in tests by %d as ddtrace creates its own objects
php <<'PHP'
<?php
foreach (explode("\0", trim(shell_exec("find . -type f -name '*.phpt' -print0"))) as $f) {
    $c = file_get_contents($f);
    $n = preg_replace(["/\)#[0-9]+ \(/", "/[0-9]+ is not a valid/"], [")#%d (", "%d is not a valid"], $c);
    if ($c !== $n) {
        file_put_contents($f, str_replace("--EXPECT--", "--EXPECTF--", $n));
    }
}
PHP

# -j flag is only available in PHP 7.4+
extra_args=""
if [[ -n "${PHP_MAJOR_MINOR}" && $(version $PHP_MAJOR_MINOR) -ge $(version 7.4) ]]; then
  extra_args="-j$(nproc)"
fi

# run-tests supports flaky since 8.1
if [[ -n "${PHP_MAJOR_MINOR}" && $(version $PHP_MAJOR_MINOR) -ge $(version 8.1) ]]; then
  sed -i "/flaky_functions = /a 'socket_create','stream_context_create'," run-tests.php
fi

# Run tests with automatic retry on infrastructure failures
max_attempts=3
attempt=1

while [ $attempt -le $max_attempts ]; do
  echo "Test run attempt $attempt/$max_attempts"

  output_file=$(mktemp)
  set +e
  php run-tests.php -q \
    -p /usr/local/bin/php \
    --show-diff \
    -g FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP \
    -d datadog.trace.sources_path=/home/circleci/datadog/src \
    $extra_args 2>&1 | tee "$output_file"
  exit_code=$?
  set -e

  if [ $exit_code -eq 0 ]; then
    rm -f "$output_file"
    exit 0
  fi

  if is_retriable_error "$(cat "$output_file")"; then
    echo "Detected retriable infrastructure error on attempt $attempt"
    if [ $attempt -lt $max_attempts ]; then
      echo "Retrying in 5 seconds..."
      sleep 5 && attempt=$((attempt + 1))
      rm -f "$output_file"
    else
      echo "Max retry attempts reached"
      rm -f "$output_file"
      exit $exit_code
    fi
  else
    echo "Non-retriable failure detected"
    rm -f "$output_file"
    exit $exit_code
  fi
done
