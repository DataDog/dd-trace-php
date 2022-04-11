--TEST--
client_init returns a not-ok verdict
--FILE--
<?php
use function datadog\appsec\testing\{rinit,backoff_status,is_without_holes};

include __DIR__ . '/inc/mock_helper.php';

$obj = new ArrayObject();
$helper = Helper::createRun([['not-ok', phpversion('ddappsec'), ['such and such error occurred'], $obj, $obj]]);

var_dump(rinit());

var_dump(backoff_status());

?>
--EXPECTF--
Warning: datadog\appsec\testing\rinit(): [ddappsec] Response to client_init is not ok: not-ok: such and such error occurred in %s on line %d

Warning: datadog\appsec\testing\rinit(): [ddappsec] Processing for command client_init failed: dd_error in %s on line %d

Warning: datadog\appsec\testing\rinit(): [ddappsec] Initial exchange with helper failed; abandoning the connection in %s on line %d
bool(true)
array(2) {
  ["failed_count"]=>
  int(1)
  ["next_retry"]=>
  float(%f)
}
