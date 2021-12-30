--TEST--
RINIT fails because helper is down
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,backoff_status,is_connected_to_helper};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([]); // respond to client_init, but not request_init

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

Warning: datadog\appsec\testing\rinit(): [ddappsec] Error %s for command request_init: dd_network in %s on line %d
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
