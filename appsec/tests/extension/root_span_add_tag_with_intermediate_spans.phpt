--TEST--
Test that tags are always added to the root span regardless of intermediate spans
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--ENV--
SERVER_NAME=localhost:8888
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,ddtrace_rshutdown,root_span_get_meta,root_span_get_metrics,root_span_add_tag};
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]]])),
], ['continuous' => true]);

echo "rinit\n";
var_dump(rinit());
$helper->get_commands(); //ignore

var_dump(root_span_add_tag("before", "root_span"));
DDTrace\start_span();
var_dump(root_span_add_tag("after", "root_span"));

DDTrace\close_span(0);

echo "rshutdown\n";
var_dump(rshutdown());
$helper->get_commands(); //ignore

echo "ddtrace_rshutdown\n";
var_dump(ddtrace_rshutdown());
dd_trace_internal_fn('synchronous_flush');

$commands = $helper->get_commands();

$span_array = $commands[0]['payload'][0];
var_dump(count($span_array));

$tags = $span_array[0]['meta'];
$metrics = $span_array[0]['metrics'];

echo "tags:\n";
ksort($tags);
print_r($tags);
echo "metrics:\n";
ksort($metrics);
print_r($metrics);

$helper->finished_with_commands();

?>
--EXPECTF--
rinit
bool(true)
bool(true)
bool(true)
rshutdown
bool(true)
ddtrace_rshutdown
bool(true)
int(2)
tags:
Array
(
    [_dd.p.dm] => -0
    [_dd.p.tid] => %s
    [_dd.runtime_family] => php
    [after] => root_span
    [before] => root_span
    [runtime-id] => %s
)
metrics:
Array
(
    [_dd.agent_psr] => 1
    [_dd.appsec.enabled] => 1
    [_sampling_priority_v1] => 1
    [php.compilation.total_time_ms] => %s
    [php.memory.peak_real_usage_bytes] => %f
    [php.memory.peak_usage_bytes] => %f
    [process_id] => %d
)
