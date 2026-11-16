--TEST--
OpenTelemetry random value is reconciled for a 64-bit Datadog drop decision
--ENV--
DD_TRACE_SAMPLE_RATE=0.05
DD_TRACE_RATE_LIMIT=10000000
--FILE--
<?php

$span = DDTrace\start_span();
$root = DDTrace\root_span();
$root->traceId = str_pad(dechex(5401449561355763072), 32, '0', STR_PAD_LEFT);

$headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
preg_match('/(?:^|,)ot=([^,]+)/', $headers['tracestate'], $matches);
echo $matches[1], ' sampled=', substr($headers['traceparent'], -1), PHP_EOL;

DDTrace\close_span();

?>
--EXPECT--
rv:f333333333332f;th:f333333333333 sampled=0
