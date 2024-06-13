--TEST--
Generate signal
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
extension=ddtrace.so
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');


use function datadog\appsec\testing\generate_signal;

function two($param01, $param02)
{
    var_dump(generate_signal());
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
var_dump($span[0]["meta_struct"]);
DDTrace\flush();
?>
--EXPECTF--
