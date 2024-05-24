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
    response_list(response_request_init(['ok', []])),
    response_list(response_request_exec(['block', ['status_code' => '404', 'type' => 'json'], ['{"found":"attack"}','{"another":"attack"}']])),
]);

rinit();
push_address("server.request.path_params", ["some" => "params", "more" => "parameters"]);

var_dump("THIS SHOULD NOT GET IN THE OUTPUT");

?>
--EXPECTHEADERS--
Status: 404 Not Found
Content-type: application/json
--EXPECTF--
{"errors": [{"title": "You've been blocked", "detail": "Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}]}
Warning: datadog\appsec\push_address(): Datadog blocked the request and presented a static error page in %s on line %d
