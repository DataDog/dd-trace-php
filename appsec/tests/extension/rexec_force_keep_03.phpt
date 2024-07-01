--TEST--
Sampling priority is not set when helper set force to false
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown, request_exec, root_span_get_metrics};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]], []])),
    response_list(response_request_exec([[['ok', []]], [], [], []])),
]);

rinit();
request_exec([
    'key 01' => 'some value',
    'key 02' => 123,
    'key 03' => ['some' => 'array']
]);
rshutdown();


echo "root_span_get_metrics():\n";
print_r(root_span_get_metrics());
?>
--EXPECTF--
root_span_get_metrics():
Array
(
    [%s] => %d
    [_dd.appsec.enabled] => 1
)
