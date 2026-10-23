--TEST--
request_shutdown forwards parsed MessagePack without applying serialization limits
--INI--
extension=ddtrace.so
expose_php=0
datadog.appsec.enabled=1
--GET--
a=b
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

header('content-type: application/json');
http_response_code(403);
var_dump(rinit());
$nested = 'leaf';
for ($i = 0; $i < 23; ++$i) {
    $nested = [$nested];
}
$expected = ['long' => str_repeat('x', 5000), 'items' => range(0, 2999), 'nested' => $nested];
echo json_encode($expected);
$helper->get_commands();

var_dump(rshutdown());
$commands = $helper->get_commands();
var_dump($commands[0][1][0]['server.response.body'] === $expected);
var_dump($commands[0][1][2]);
--EXPECTF--
bool(true)
%sbool(true)
bool(true)
bool(false)
