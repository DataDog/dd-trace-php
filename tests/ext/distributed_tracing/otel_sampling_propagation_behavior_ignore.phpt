--TEST--
OpenTelemetry sampling state is regenerated when extraction ignores the context
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT=ignore
DD_TRACE_PROPAGATION_STYLE_EXTRACT=tracecontext
DD_TRACE_SAMPLE_RATE=0.5
DD_TRACE_RATE_LIMIT=10000000
--FILE--
<?php

$inboundTraceId = '0000000000000000fff972474538efff';
$inboundRandomValue = '1234567890abcd';
DDTrace\consume_distributed_tracing_headers([
    'traceparent' => "00-$inboundTraceId-0000000000000001-01",
    'tracestate' => "ot=rv:$inboundRandomValue;th:e6666666666668",
]);

DDTrace\start_span();
$headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
preg_match('/(?:^|,)ot=([^,]+)/', $headers['tracestate'], $matches);
$ot = $matches[1] ?? '';

echo 'new trace: ', DDTrace\root_span()->traceId === $inboundTraceId ? 'no' : 'yes', PHP_EOL;
echo 'new probability state: ', preg_match('/^rv:[0-9a-f]{14};th:8$/', $ot) ? 'yes' : 'no', PHP_EOL;
echo 'inbound rv removed: ', strpos($ot, $inboundRandomValue) === false ? 'yes' : 'no', PHP_EOL;

DDTrace\close_span();

?>
--EXPECT--
new trace: yes
new probability state: yes
inbound rv removed: yes
