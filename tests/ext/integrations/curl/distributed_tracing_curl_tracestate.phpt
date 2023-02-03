--TEST--
Distributed tracing headers propagate with curl_exec()
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--INI--
ddtrace.request_init_hook={PWD}/distributed_tracing_curl_inject.inc
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_GENERATE_ROOT_SPAN=0
HTTP_X_TRACEPARENT=00-12345678901234567890123456789012-6543210987654321-01
HTTP_X_TRACESTATE=foo=bar:;=,dd=o:phpt-test;unknown1:val;t.test:qvalue;s:2;unknown2:1,baz=qux
DD_PROPAGATION_STYLE_INJECT=B3 single header,tracecontext
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
curl_close($ch);

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
b3: 12345678901234567890123456789012-6543210987654321-d
traceparent: 00-12345678901234567890123456789012-6543210987654321-01
tracestate: dd=o:phpt-test;s:2;t.tid:1234567890123456;t.test:qvalue;unknown1:val;unknown2:1,foo=bar:;=,baz=qux
x-datadog-origin: phpt-test
x-datadog-tags: _dd.p.tid=1234567890123456,_dd.p.test=qvalue
bool(false)
Done.
No finished traces to be sent to the agent
