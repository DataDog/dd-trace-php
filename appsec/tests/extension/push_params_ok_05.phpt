--TEST--
Rasp addresses are not sent to the helper if RASP disabled
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--ENV--
DD_APPSEC_RASP_ENABLED=false
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
use function datadog\appsec\push_address;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

var_dump(rinit());
$is_rasp = true;
push_address("server.request.path_params", 1234, $is_rasp);
var_dump(rshutdown());

var_dump($helper->get_command("request_exec"));

?>
--EXPECTF--
bool(true)
bool(true)
array(0) {
}
