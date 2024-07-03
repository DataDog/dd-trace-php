--TEST--
Agent host and port fallback to default when agent url ones are not valid
--ENV--
DD_TRACE_AGENT_URL=http://:99999
DD_TRACE_AGENT_PORT=
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
var_dump($commands[0][1][6]["host"]);
var_dump($commands[0][1][6]["port"]);
?>
--EXPECTF--
bool(true)
bool(true)
string(%d) "client_init"
string(%d) "127.0.0.1"
int(8126)
