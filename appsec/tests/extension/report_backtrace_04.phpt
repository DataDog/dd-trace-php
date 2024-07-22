--TEST--
Trace are reported when helper indicates so
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown, report_backtrace};
use function datadog\appsec\push_address;
include __DIR__ . '/inc/ddtrace_version.php';
include __DIR__ . '/inc/mock_helper.php';

ddtrace_version_at_least('0.79.0');

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['stack_trace', ['stack_id' => '1234']]], []])),
]);

function two($param01, $param02)
{
    push_address("irrelevant", ["some" => "params", "more" => "parameters"]);
}

function one($param01)
{
    two($param01, "other");
}

rinit();

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
array(1) {
  ["_dd.stack"]=>
  &string(292) "81a76578706c6f69749183a86c616e6775616765a3706870a26964a431323334a66672616d65739284a46c696e6515a866756e6374696f6ea374776fa466696c65b77265706f72745f6261636b74726163655f30342e706870a269640084a46c696e651ca866756e6374696f6ea36f6e65a466696c65b77265706f72745f6261636b74726163655f30342e706870a2696401"
}
