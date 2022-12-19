--TEST--
test request recording failure due to no root span available
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--FILE--
<?php
use function datadog\appsec\testing\{rinit,ddtrace_rshutdown,root_span_get_meta};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

$helper = Helper::createInitedRun([
    response_list(response_request_init(['record', '[{"found":"attack"}]']))
], ['continuous' => true]);

echo "root_span_get_meta (should fail: no root span):\n";
var_dump(root_span_get_meta());

echo "rinit\n";
var_dump(rinit());
$helper->get_commands(); //ignore

DDTrace\start_span();
DDTrace\close_span(0);

echo "ddtrace_rshutdown\n";
var_dump(ddtrace_rshutdown());
dd_trace_internal_fn('synchronous_flush');

$commands = $helper->get_commands();
$tags = $commands[0]['payload'][0][0]['meta'];

echo "tags:\n";
print_r($tags);

$helper->finished_with_commands();

?>
--EXPECTF--
root_span_get_meta (should fail: no root span):
NULL
rinit
bool(true)
ddtrace_rshutdown
bool(true)
tags:
Array
(
    [%s] => %d
    [_dd.p.dm] => -1
)
