--TEST--
Report backtrace
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
extension=ddtrace.so
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');


use function datadog\appsec\testing\report_backtrace;

function two($param01, $param02)
{
    var_dump(report_backtrace());
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
$meta_struct = $span[0]["meta_struct"];
foreach($meta_struct as &$m)
{
    $m = bin2hex($m);
}
var_dump($meta_struct);
DDTrace\flush();
?>
--EXPECTF--
bool(true)
array(1) {
  ["_dd.stack"]=>
  &string(376) "9384a46c696e650ba866756e6374696f6ed92764617461646f675c6170707365635c74657374696e675c7265706f72745f6261636b7472616365a466696c65b47265706f72745f6261636b74726163652e706870a269640084a46c696e6510a866756e6374696f6ea374776fa466696c65b47265706f72745f6261636b74726163652e706870a269640184a46c696e6515a866756e6374696f6ea36f6e65a466696c65b47265706f72745f6261636b74726163652e706870a2696402"
}