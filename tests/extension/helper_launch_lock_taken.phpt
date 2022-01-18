--TEST--
Can't launch helper because the lock is taken
--INI--
datadog.appsec.helper_runtime_path=/tmp/appsec-ext-test/
datadog.appsec.helper_path=/usr/bin/true
datadog.appsec.helper_launch=1
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=info
--FILE--
<?php
use function datadog\appsec\testing\{helper_mgr_acquire_conn,backoff_status};

$version = phpversion('ddappsec');
$runtime_path = ini_get('datadog.appsec.helper_runtime_path');
$sock_path = "$runtime_path/ddappsec_$version.sock";
$lock_path = "$runtime_path/ddappsec_$version.lock";

$f = fopen($lock_path, "c");
var_dump(flock($f, LOCK_EX));

var_dump(helper_mgr_acquire_conn());

require __DIR__ . '/inc/logging.php';
match_log("/Attempting to connect to UNIX socket \/tmp\/appsec-ext-test\/ddappsec_" . $version . ".sock/");
match_log("/The helper lock on \/tmp\/appsec-ext-test\/ddappsec_" . $version . ".lock is already being held/");

var_dump(backoff_status());
?>
--EXPECTF--
bool(true)
bool(false)
found message in log matching /Attempting to connect to UNIX socket \/tmp\/appsec-ext-test\/ddappsec_%s.sock/
found message in log matching /The helper lock on \/tmp\/appsec-ext-test\/ddappsec_%s.lock is already being held/
array(2) {
  ["failed_count"]=>
  int(1)
  ["next_retry"]=>
  float(%f)
}
