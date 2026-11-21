--TEST--
request_init answered with another kind of verdict skips AppSec for the request
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_level=trace
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

$helper = Helper::createRun([
    response_list(response_client_init(['msg' => ['y' => 'ok']])),
    response_list(response_request_shutdown([[['ok', []]]])),
]);

var_dump(rinit());
var_dump(rshutdown());

match_log('/The AppSec helper failed request_init: unexpected response "client_init" to "request_init"/');
echo implode(", ", array_column($helper->get_commands(), 0)), "\n";
?>
--EXPECTF--
bool(true)
bool(true)
found message in log matching /The AppSec helper failed request_init: unexpected response "client_init" to "request_init"/
request_init
