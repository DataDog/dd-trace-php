--TEST--
Fallback to default port if given not valid
--ENV--
DD_TRACE_AGENT_PORT=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init(['ok', []]))
]);

var_dump(rinit());
var_dump(rshutdown());

$commands = $helper->get_commands();
var_dump($commands[0][0]); //Command name - client_init
var_dump($commands[0][1][6]["port"]);

?>
--EXPECTF--
bool(true)
bool(true)
string(%d) "client_init"
int(8126)
