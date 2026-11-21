--TEST--
When extension is not configured, the helper is only contacted once remote config enables it
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--ENV--
DD_SERVICE=appsec_tests
DD_ENV=prod
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,set_remote_asm_features};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]]))
]);

var_dump(rinit());
var_dump(rshutdown());

set_remote_asm_features(true);
var_dump(rinit());
var_dump(rshutdown());

$commands = $helper->get_commands();
// The scripted response is used up by request_init: the mock stops before
// request_shutdown. The rinit before the activation sent nothing.
var_dump(count($commands));
var_dump($commands[0][0]);

?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
bool(true)
int(1)
string(12) "request_init"
