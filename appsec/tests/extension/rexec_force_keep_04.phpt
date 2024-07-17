--TEST--
Force keep on rshutdown, overrides rinit
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown, request_exec, root_span_get_metrics};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]], [], false])),
    response_list(response_request_exec([[['ok', []]], [], true])),
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

var_dump(\DDTrace\get_priority_sampling());
?>
--EXPECTF--
root_span_get_metrics():
Array
(
    [%s] => %d
    [_dd.appsec.enabled] => 1
)
int(2)
