--TEST--
Can't launch helper because the lock is taken
--INI--
ddappsec.helper_path=/usr/bin/true
ddappsec.helper_lock_path=/tmp/lock_taken.lock
ddappsec.helper_launch=1
--FILE--
<?php
use function datadog\appsec\testing\{helper_mgr_acquire_conn,backoff_status};

$f = fopen(ini_get('ddappsec.helper_lock_path'), 'c');
var_dump(flock($f, LOCK_EX));

var_dump(helper_mgr_acquire_conn());
var_dump(backoff_status());
?>
--EXPECTF--
bool(true)

Warning: datadog\appsec\testing\helper_mgr_acquire_conn(): [ddappsec] Connection to helper failed and we are not going to attempt to launch it: dd_error in %s on line %d
bool(false)
array(2) {
  ["failed_count"]=>
  int(1)
  ["next_retry"]=>
  float(%f)
}
