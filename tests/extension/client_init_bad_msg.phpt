--TEST--
client_init returns a response with an incorrect format
--FILE--
<?php
use function datadog\appsec\testing\{rinit,backoff_status,is_without_holes};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createRun([['msg' => ['y' => 'ok']]]);

var_dump(rinit());

var_dump(backoff_status());

?>
--EXPECTF--
Warning: datadog\appsec\testing\rinit(): [ddappsec] Unexpected client_init response: mpack_error_type in %s on line %d

Warning: datadog\appsec\testing\rinit(): [ddappsec] Response message for client_init does not have the expected form in %s on line %d

Warning: datadog\appsec\testing\rinit(): [ddappsec] Initial exchange with helper failed; abandoning the connection in %s on line %d
bool(true)
array(2) {
  ["failed_count"]=>
  int(1)
  ["next_retry"]=>
  float(%f)
}
