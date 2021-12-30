--TEST--
Record response from request_init and ancillary tags in root span
--INI--
extension=ddtrace.so
ddappsec.log_file=/tmp/php_appsec_test.log
ddappsec.log_level=debug
--SKIPIF--
<?php
include __DIR__ . '/inc/ddtrace_version.php';
ddtrace_version_at_least('0.67.0');
?>
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

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    ['record', ['{"found":"attack"}','{"another":"attack"}']],
    ['record', ['{"yet another":"attack"}']],
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
print_r($tags);
echo "metrics:\n";
print_r($metrics);

$helper->finished_with_commands();

?>
--EXPECTF--
root_span_get_meta():
Array
(
    [system.pid] => %d
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
    [system.pid] => %d
    [_dd.runtime_family] => php
    [_dd.appsec.json] => {"triggers":[{"found":"attack"},{"another":"attack"},{"yet another":"attack"}]}
    [appsec.event] => true
    [http.method] => GET
    [http.url] => https://localhost:8888/foo.php
    [http.useragent] => my user agent
    [http.status_code] => 200
    [http.request.headers.user-agent] => my user agent
    [http.response.headers.content-type] => text/html%s
)
metrics:
Array
(
    [_dd.appsec.enabled] => 1
    [_sampling_priority_v1] => 2
    [php.compilation.total_time_ms] => %f
)
