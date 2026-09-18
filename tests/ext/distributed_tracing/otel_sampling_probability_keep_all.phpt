--TEST--
Full-rate probability sampling emits a single zero threshold digit
--ENV--
DD_TRACE_SAMPLE_RATE=1
DD_TRACE_RATE_LIMIT=10000000
--FILE--
<?php

foreach (['1', 'a'] as $traceId) {
    DDTrace\start_span();
    DDTrace\root_span()->traceId = str_pad($traceId, 32, '0', STR_PAD_LEFT);
    $headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
    preg_match('/(?:^|,)ot=([^,]+)/', $headers['tracestate'], $matches);
    echo $matches[1], ' sampled=', substr($headers['traceparent'], -1), PHP_EOL;
    DDTrace\close_span();
}
?>
--EXPECT--
rv:f0948a54d43b8e;th:0 sampled=1
rv:65cd67504a538e;th:0 sampled=1
