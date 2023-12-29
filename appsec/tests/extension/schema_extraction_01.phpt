--TEST--
Default schema extraction configurations
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,get_formatted_runtime_id};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init(['ok', []]))
]);

var_dump(rinit());
var_dump(rshutdown());

$clientInit = $helper->get_command('client_init');

var_dump($clientInit[1][5]['schema_extraction']);
?>
--EXPECTF--
bool(true)
bool(true)
array(2) {
  ["enabled"]=>
  bool(false)
  ["sample_rate"]=>
  float(0.1)
}
