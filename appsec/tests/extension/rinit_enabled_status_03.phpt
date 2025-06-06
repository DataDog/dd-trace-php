--TEST--
When extension is not configured, it is sent to the helper
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]]))
]);

var_dump(rinit());
var_dump(rshutdown());

$commands = $helper->get_commands();
var_dump($commands[0][0]); //Command name - client_init
var_dump($commands[0][1][3]); //enabled_configuration

?>
--EXPECTF--
bool(true)
bool(true)
string(%d) "client_init"
NULL
