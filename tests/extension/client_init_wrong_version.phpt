--TEST--
client_init returns a mismatched version
--FILE--
<?php
use function datadog\appsec\testing\{rinit,backoff_status,is_without_holes};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createRun([['ok', '0.0.0']]);

var_dump(rinit());

var_dump(backoff_status());

?>
--EXPECTF--
Warning: datadog\appsec\testing\rinit(): [ddappsec] Mismatch of helper and extension version. helper 0.0.0 and extension %s in %s on line %s

Warning: datadog\appsec\testing\rinit(): [ddappsec] Processing for command client_init failed: dd_error in %s on line %d

Warning: datadog\appsec\testing\rinit(): [ddappsec] Initial exchange with helper failed; abandoning the connection in %s on line %d
bool(true)
array(2) {
  ["failed_count"]=>
  int(1)
  ["next_retry"]=>
  float(%f)
}
