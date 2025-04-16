--TEST--
Sampling priority is set to 2 when helper forces keep
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown, root_span_get_metrics};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]], []])),
    response_list(response_request_shutdown([[['ok', []]], [], true, [], []])),
]);

var_dump(rinit());
var_dump(rshutdown());

echo "root_span_get_metrics():\n";
print_r(root_span_get_metrics());

var_dump(\DDTrace\get_priority_sampling());
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
int(2)
