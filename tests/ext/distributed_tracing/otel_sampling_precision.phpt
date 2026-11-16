--TEST--
OpenTelemetry random value is reconciled with the 64-bit Datadog sampling decision
--ENV--
DD_TRACE_SAMPLE_RATE=0.1
DD_TRACE_RATE_LIMIT=10000000
--FILE--
<?php

$span = DDTrace\start_span();
$root = DDTrace\root_span();
$root->traceId = str_pad('03a93ee8b1999f00', 32, '0', STR_PAD_LEFT);

$headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
preg_match('/(?:^|,)ot=([^,]+)/', $headers['tracestate'], $matches);
echo $matches[1], ' sampled=', substr($headers['traceparent'], -1), PHP_EOL;

DDTrace\close_span();

?>
--EXPECT--
rv:e6666666666668;th:e6666666666668 sampled=1
