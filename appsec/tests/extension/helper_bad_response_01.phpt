--TEST--
Simulate helper response with an unexpected type of message
--INI--
datadog.appsec.log_level=trace
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

$helper = Helper::createInitedRun([
    //At this stage is already initated so extension is not expecting a client init response again
    response_list(response_client_init(['msg' => ['y' => 'ok']]))
]);

var_dump(rinit());

match_log('/Received message for command config_sync unexpected: "client_init"/');


?>
--EXPECTF--
bool(true)
found message in log matching /Received message for command config_sync unexpected: "client_init"/
