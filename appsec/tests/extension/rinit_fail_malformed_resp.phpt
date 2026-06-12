--TEST--
RINIT fails because helper sent a malformed response
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,backoff_status,is_connected_to_helper};

include __DIR__ . '/inc/mock_helper.php';

// The malformed request_init response triggers dd_network, closing the connection.
// rshutdown won't communicate with the helper.
$helper = Helper::createInitedRun([
    response_list(
        response_request_init([['foo' => 'ok']])
    ),
]);

echo "rinit:\n";
var_dump(rinit());
echo "rshutdown:\n";
var_dump(rshutdown());
echo "is connected:\n";
var_dump(is_connected_to_helper());
var_dump(backoff_status());

?>
--EXPECTF--
rinit:

Warning: datadog\appsec\testing\rinit(): [ddappsec] Response message for request_init does not have the expected form in %s on line %d
bool(true)
rshutdown:
bool(true)
is connected:
bool(false)
array(2) {
  ["failed_count"]=>
  int(1)
  ["next_retry"]=>
  float(%f)
}
