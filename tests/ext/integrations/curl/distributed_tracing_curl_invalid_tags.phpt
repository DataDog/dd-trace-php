--TEST--
Distributed tracing headers contain properly escaped values
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--INI--
ddtrace.request_init_hook={PWD}/distributed_tracing_curl_inject.inc
--ENV--
DD_TRACE_DEBUG=1
DD_PROPAGATION_STYLE_INJECT=B3 single header,tracecontext
DD_TRACE_DEBUG_PRNG_SEED=1000
--FILE--
<?php
include 'curl_helper.inc';

DDTrace\add_distributed_tag("escaped", "_=;: ");
DDTrace\set_distributed_tracing_context(0, 0, "\n\t∂~,=;: ");

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
b3: 248869c998246a2e-248869c998246a2e-1
traceparent: 00-0000000000000000248869c998246a2e-248869c998246a2e-01
tracestate: dd=o:_____~___: ;t.escaped:_~_: ;t.dm:-1
x-datadog-origin: ∂~,=;:
x-datadog-tags: _dd.p.escaped=_=;: ,_dd.p.dm=-1
bool(false)
Done.
No finished traces to be sent to the agent
