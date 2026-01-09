#!/usr/bin/env bash
set -eo pipefail

# Helper to parse version strings for comparison
function version { echo "$@" | awk -F. '{ printf("%d%03d%03d%03d\n", $1,$2,$3,$4); }'; }

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
mkdir -p "${CI_PROJECT_DIR}/artifacts/tests"
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

php run-tests.php -q \
  -p /usr/local/bin/php \
  --show-diff \
  -g FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP \
  -d datadog.trace.sources_path=/home/circleci/datadog/src \
  $extra_args
