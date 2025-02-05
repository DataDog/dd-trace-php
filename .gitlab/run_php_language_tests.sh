#!/usr/bin/env bash
set -eo pipefail

sudo rm -f /opt/php/debug/conf.d/memcached.ini
if [[ ! "${XFAIL_LIST:-none}" == "none" ]]; then
  cp "${XFAIL_LIST}" /usr/local/src/php/xfail_tests.list
  (
    cd /usr/local/src/php
    cat xfail_tests.list | xargs -n 1 -I{} find {} -name "*.phpt" -delete || true
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
php run-tests.php -q \
  -p /usr/local/bin/php \
  --show-diff \
  -g FAIL,XFAIL,BORK,WARN,LEAK,XLEAK,SKIP \
  -d datadog.trace.sources_path=/home/circleci/datadog/src \
  -j5
