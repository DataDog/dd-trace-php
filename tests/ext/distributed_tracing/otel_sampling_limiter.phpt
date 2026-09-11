--TEST--
OpenTelemetry threshold is not emitted when the trace rate limiter makes the drop decision
--SKIPIF--
<?php if (getenv('USE_ZEND_ALLOC') === '0') die('skip timing sensitive test, does not make sense with valgrind'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_RATE_LIMIT=10
DD_TRACE_SAMPLE_RATE=1
--FILE--
<?php

for ($i = 0; $i < 1000; ++$i) {
    DDTrace\start_span();
    $headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
    DDTrace\close_span();
    dd_trace_serialize_closed_spans();

    $traceFlags = hexdec(substr($headers['traceparent'], -2));
    if (($traceFlags & 1) === 0) {
        echo strpos($headers['tracestate'], 'ot=') === false ? "OK\n" : "unexpected ot member\n";
        return;
    }
}

echo "rate limiter did not reject a trace\n";

?>
--EXPECT--
OK
