--TEST--
OpenTelemetry tracestate fields are emitted for probability sampling decisions
--ENV--
DD_TRACE_SAMPLE_RATE=0.5
DD_TRACE_RATE_LIMIT=10000000
--FILE--
<?php

function sample(string $traceId)
{
    $span = DDTrace\start_span();
    $root = DDTrace\root_span();
    $root->traceId = str_pad($traceId, 32, '0', STR_PAD_LEFT);

    $headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
    preg_match('/(?:^|,)ot=([^,]+)/', $headers['tracestate'], $matches);
    echo $matches[1], ' sampled=', substr($headers['traceparent'], -1), PHP_EOL;

    DDTrace\close_span();
}

sample('1');
sample('a');

?>
--EXPECT--
rv:f0948a54d43b8e;th:8 sampled=1
rv:65cd67504a538e;th:8 sampled=0
