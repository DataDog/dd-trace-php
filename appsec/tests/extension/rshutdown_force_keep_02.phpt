--TEST--
Sampling priority is not set when helper set force to false
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown, root_span_get_metrics};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]], []])),
    response_list(response_request_shutdown([[['ok', []]], [], [], [], false])),
]);

var_dump(rinit());
var_dump(rshutdown());

echo "root_span_get_metrics():\n";
print_r(root_span_get_metrics());
?>
--EXPECTF--
bool(true)
bool(true)
root_span_get_metrics():
Array
(
    [%s] => %d
    [_dd.appsec.enabled] => 1
)
