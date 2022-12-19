--TEST--
Record response from request_init and ancillary tags in root span
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--ENV--
DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=0
HTTPS=on
SERVER_NAME=localhost:8888
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo
METHOD=GET
HTTP_USER_AGENT=my user agent
--GET--
a=b
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,ddtrace_rshutdown,root_span_get_meta};
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createRun([
    response_list(
        response_client_init(['ok', phpversion('ddappsec'), [],
        ["meta_1" => "value_1", "meta_2" => "value_2"],
        ["metric_1" => 2.0, "metric_2" => 10.0]])
    ),
    response_list(
        response_request_init(['record', ['{"found":"attack"}','{"another":"attack"}']])
    ),
    response_list(
        response_request_shutdown(['record', ['{"yet another":"attack"}']])
    ),
], ['continuous' => true]);

echo "rinit\n";
var_dump(rinit());
$helper->get_commands(); //ignore

echo "rshutdown\n";
var_dump(rshutdown());
$helper->get_commands(); //ignore

echo "ddtrace_rshutdown\n";
var_dump(ddtrace_rshutdown());
dd_trace_internal_fn('synchronous_flush');

$commands = $helper->get_commands();
$tags = $commands[0]['payload'][0][0]['meta'];
$metrics = $commands[0]['payload'][0][0]['metrics'];

echo "tags:\n";
ksort($tags);
print_r($tags);
echo "metrics:\n";
print_r($metrics);

$helper->finished_with_commands();

?>
--EXPECTF--
root_span_get_meta():
Array
(
    [%s] => %d
    [http.url] => https://localhost:8888/foo
    [http.method] => GET
    [http.useragent] => my user agent
)
rinit
bool(true)
rshutdown
bool(true)
ddtrace_rshutdown
bool(true)
tags:
Array
(
    [_dd.appsec.json] => {"triggers":[{"found":"attack"},{"another":"attack"},{"yet another":"attack"}]}
    [_dd.runtime_family] => php
    [appsec.event] => true
    [http.method] => GET
    [http.request.headers.user-agent] => my user agent
    [http.response.headers.content-type] => text/html; charset=UTF-8
    [http.status_code] => 200
    [http.url] => https://localhost:8888/foo
    [http.useragent] => my user agent
    [meta_1] => value_1
    [meta_2] => value_2
    [%s] => %d
)
metrics:
Array
(
    [_sampling_priority_v1] => 2
    [metric_1] => 2
    [metric_2] => 10
    [_dd.appsec.enabled] => 1
    [php.compilation.total_time_ms] => %f
)
