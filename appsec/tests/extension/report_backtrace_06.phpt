--TEST--
DD_APPSEC_STACK_TRACE_ENABLED can be disabled
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_APPSEC_STACK_TRACE_ENABLED=0
--INI--
extension=ddtrace.so
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

use function datadog\appsec\testing\report_exploit_backtrace;

function two($param01, $param02)
{
    report_exploit_backtrace("some id");
}

function one($param01)
{
    two($param01, "other");
}

DDTrace\start_span();
$root = DDTrace\active_span();
one("foo");
DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
var_dump(isset($span[0]["meta_struct"]));
DDTrace\flush();
?>
--EXPECTF--
bool(false)
