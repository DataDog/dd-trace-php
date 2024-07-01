--TEST--
Push address gets blocked
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
use function datadog\appsec\push_address;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['redirect', ['status_code' => '303', 'location' => 'https://datadoghq.com']]], []])),
]);

rinit();
push_address("server.request.path_params", ["some" => "params", "more" => "parameters"]);

var_dump("THIS SHOULD NOT GET IN THE OUTPUT");

?>
--EXPECTHEADERS--
Status: 303 See Other
Content-type: text/html; charset=UTF-8
--EXPECTF--
Warning: datadog\appsec\push_address(): Datadog blocked the request and attempted a redirection to https://datadoghq.com in %s on line %d
