--TEST--
Explicitly drop dd.p.dm if provided in propagated tags when the incoming sampling priority is reject
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_GENERATE_ROOT_SPAN=0
HTTP_TRACEPARENT=00-00000012345678907890123456789012-1234567890123456-00
HTTP_TRACESTATE=foo=1,dd=s:1;t.dm:-0;t.usr.id:baz64~~;t.url:http://localhost
DD_PROPAGATION_STYLE=datadog,tracecontext
--FILE--
<?php
include 'curl_helper.inc';

$port = getenv('HTTPBIN_PORT') ?: '80';
$url = 'http://' . getenv('HTTPBIN_HOSTNAME') . ':' . $port .'/headers';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
show_curl_error_on_fail($ch);
if (PHP_VERSION_ID < 80000) { curl_close($ch); }

include 'distributed_tracing.inc';
$headers = dt_decode_headers_from_httpbin($response);
dt_dump_headers_from_httpbin($headers, [
    'x-datadog-trace-id',
    'x-datadog-parent-id',
    'x-datadog-origin',
    'x-datadog-tags',
    'b3',
    'traceparent',
    'tracestate',
]);

$spans = dd_trace_serialize_closed_spans();
var_dump(isset($spans[0]["meta"]["_dd.propagation_error"]));

echo 'Done.' . PHP_EOL;

?>
--EXPECTF--
traceparent: 00-00000012345678907890123456789012-1234567890123456-00
tracestate: dd=t.usr.id:baz64~~;t.url:http://localhost,foo=1
x-datadog-parent-id: 1311768467284833366
x-datadog-tags: _dd.p.tid=0000001234567890,_dd.p.usr.id=baz64==,_dd.p.url=http://localhost
x-datadog-trace-id: 8687463697196027922
bool(false)
Done.
[ddtrace] [info] No finished traces to be sent to the agent
