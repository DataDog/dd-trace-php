--TEST--
Basic RINIT/RSHUTDOWN sequence with mock helper
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_SAMPLE_DELAY=0.0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,get_formatted_runtime_id};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]]))
]);

var_dump(rinit());
$c = $helper->get_commands();
var_dump($c[0][1][4]['schema_extraction']);
?>
--EXPECTF--
bool(true)
array(2) {
  ["enabled"]=>
  bool(true)
  ["sampling_period"]=>
  float(0)
}
