--TEST--
Can't launch helper because the lock is taken
--INI--
datadog.appsec.helper_socket_path=/does/not/exist
datadog.appsec.helper_path=/usr/bin/true
datadog.appsec.helper_lock_path=/tmp/lock_taken.lock
datadog.appsec.helper_launch=1
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=info
--FILE--
<?php
use function datadog\appsec\testing\{helper_mgr_acquire_conn,backoff_status};

$f = fopen(ini_get('datadog.appsec.helper_lock_path'), 'c');
var_dump(flock($f, LOCK_EX));

var_dump(helper_mgr_acquire_conn());

require __DIR__ . '/inc/logging.php';
match_log('/Attempting to connect to UNIX socket \/does\/not\/exist/');
match_log('/The helper lock on \/tmp\/lock_taken.lock is already being held/');

var_dump(backoff_status());
?>
--EXPECTF--
bool(true)
bool(false)
found message in log matching /Attempting to connect to UNIX socket \/does\/not\/exist/
found message in log matching /The helper lock on \/tmp\/lock_taken.lock is already being held/
array(2) {
  ["failed_count"]=>
  int(1)
  ["next_retry"]=>
  float(%f)
}
