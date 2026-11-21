--TEST--
request_exec answered with another kind of verdict
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit,request_exec};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

$helper = Helper::createRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_client_init(['msg' => ['y' => 'ok']])),
]);

var_dump(rinit());
var_dump(request_exec(['server.request.path_params' => ['a' => 'b']]));

match_log('/The AppSec helper failed request_exec: unexpected response "client_init" to "request_exec"/');
?>
--EXPECTF--
bool(true)
bool(false)
found message in log matching /The AppSec helper failed request_exec: unexpected response "client_init" to "request_exec"/
