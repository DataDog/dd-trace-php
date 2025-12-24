--TEST--
Push addresses with subctx_id and subctx_last_call options
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
use function datadog\appsec\push_addresses;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['ok', []]], [], [], [], false])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

var_dump(rinit());

push_addresses(
    ["server.request.path_params" => ["some" => "params"]],
    [
        "subctx_id" => "test-context-123",
        "subctx_last_call" => true
    ]
);

var_dump(rshutdown());

$cmd = $helper->get_command("request_exec");

echo "Checking subctx_id is present:\n";
var_dump(isset($cmd[1][1]['subctx_id']));
echo "subctx_id value:\n";
var_dump($cmd[1][1]['subctx_id']);

echo "Checking subctx_last_call is present:\n";
var_dump(isset($cmd[1][1]['subctx_last_call']));
echo "subctx_last_call value:\n";
var_dump($cmd[1][1]['subctx_last_call']);

?>
--EXPECTF--
bool(true)
bool(true)
Checking subctx_id is present:
bool(true)
subctx_id value:
string(16) "test-context-123"
Checking subctx_last_call is present:
bool(true)
subctx_last_call value:
bool(true)
