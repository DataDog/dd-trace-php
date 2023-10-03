--TEST--
The helper exits immediately
--SKIPIF--
<?php
if (key_exists('USE_ZEND_ALLOC', $_ENV) && $_ENV['USE_ZEND_ALLOC'] == '0' &&
    key_exists('CI', $_ENV) && $_ENV['CI'] === 'true') {
    die('skip opaque failure in CI with valgrind');
}
?>
--INI--
datadog.appsec.helper_path=/usr/bin/true
datadog.appsec.helper_launch=1
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
include __DIR__ . "/inc/logging.php";
use function datadog\appsec\testing\{helper_mgr_acquire_conn,backoff_status};

var_dump(helper_mgr_acquire_conn());
var_dump(backoff_status());

// when a connection is attempted, it may be that true has not yet been executed
// or has not exited yet. In that case, the unix socket is still open, so we
// can connect
match_log(
    '/Error receiving reply for command client_init/',
    '/Error sending message for command client_init: dd_network/',
    '/Connection to helper failed; we tried to launch it and connect again, only to fail again/'
);
?>
--EXPECTF--
bool(false)
array(2) {
  ["failed_count"]=>
  int(1)
  ["next_retry"]=>
  float(%f)
}
found message in log matching /%s/
