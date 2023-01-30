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
DD_TRACE_X_DATADOG_TAGS_MAX_LENGTH=25
HTTP_X_DATADOG_ORIGIN=phpt-test
HTTP_X_DATADOG_TAGS=_dd.p.very=looooooooooooooooong
DD_PROPAGATION_STYLE_INJECT=B3,B3 single header,Datadog,tracecontext
--FILE--
<?php
include 'curl_helper.inc';

DDTrace\trace_function('curl_exec', function (\DDTrace\SpanData $span) {
    $span->name = 'curl_exec';
});

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
    'b3',
    'x-b3-traceid',
    'x-b3-spanid',
    'traceparent',
    'tracestate',
]);

$spans = dd_trace_serialize_closed_spans();
var_dump($headers['x-datadog-parent-id'] === (string) $spans[0]['span_id']);
var_dump(abs(hexdec($headers['x-b3-spanid']) - $spans[0]['span_id']) < (1 << 13));
var_dump(abs(hexdec($headers['x-b3-traceid']) - $headers['x-datadog-trace-id']) < (1 << 13));
var_dump($headers['b3'] == "{$headers['x-b3-traceid']}-{$headers['x-b3-spanid']}-1");
var_dump($spans[0]["meta"]["_dd.propagation_error"]);

echo 'Done.' . PHP_EOL;

?>
--EXPECTF--
The to be propagated tag '_dd.p.very=looooooooooooooooong' is too long and exceeds the maximum limit of 25 characters and is thus dropped.
b3: %s-%s-1
traceparent: 00-%s-%s
tracestate: dd=o:phpt-test
x-b3-spanid: %s
x-b3-traceid: %s
x-datadog-origin: phpt-test
x-datadog-parent-id: %d
x-datadog-trace-id: %d
bool(true)
bool(true)
bool(true)
bool(true)
string(15) "inject_max_size"
Done.
No finished traces to be sent to the agent
