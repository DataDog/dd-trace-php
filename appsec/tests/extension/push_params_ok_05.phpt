--TEST--
Rasp addresses are not sent to the helper if RASP disabled
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--ENV--
DD_APPSEC_RASP_ENABLED=false
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,root_span_get_metrics};
use function datadog\appsec\push_addresses;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

var_dump(rinit());
push_addresses(["server.request.path_params" => 1234], "lfi");
var_dump(rshutdown());
print_r(root_span_get_metrics());

var_dump($helper->get_command("request_exec"));

?>
--EXPECTF--
bool(true)
bool(true)
Array
(
    [process_id] => %d
    [_dd.appsec.enabled] => %d
)
array(0) {
}
