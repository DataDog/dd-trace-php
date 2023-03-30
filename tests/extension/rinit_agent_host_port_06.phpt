--TEST--
Agent host and port can be taken from agent url on ENV
--ENV--
DD_TRACE_AGENT_URL=http://1.2.3.4:567
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
string(%d) "1.2.3.4"
int(567)
