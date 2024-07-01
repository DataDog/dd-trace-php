--TEST--
RINIT fails because helper sent a malformed response
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,backoff_status,is_connected_to_helper};

include __DIR__ . '/inc/mock_helper.php';

// respond correctly to client_init and request_shutdown, but not request_init
$obj = new ArrayObject();
$helper = Helper::createInitedRun([
    response_list(
        response_request_init([['foo' => 'ok']])
    ),
    response_list(
        response_request_shutdown([[['ok', []]], $obj, $obj])
    )
]);

echo "rinit:\n";
var_dump(rinit());
// connection wasn't closed because this was no dd_network error. rshutdown will succeed
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
bool(true)
array(2) {
  ["failed_count"]=>
  int(0)
  ["next_retry"]=>
  float(0)
}
