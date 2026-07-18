--TEST--
W3C trace flags reflect sampling and trace-id random provenance
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

function generated_trace_flags() {
    $headers = DDTrace\generate_distributed_tracing_headers(['tracecontext']);
    return substr($headers['traceparent'], -2);
}

$root = DDTrace\start_trace_span();
$root->samplingPriority = DD_TRACE_PRIORITY_SAMPLING_USER_KEEP;
echo 'local keep: ', generated_trace_flags(), PHP_EOL;

$root->samplingPriority = DD_TRACE_PRIORITY_SAMPLING_USER_REJECT;
echo 'local reject: ', generated_trace_flags(), PHP_EOL;

$root->traceId = '11111111111111112222222222222222';
$root->samplingPriority = DD_TRACE_PRIORITY_SAMPLING_USER_KEEP;
echo 'manual keep: ', generated_trace_flags(), PHP_EOL;

$root->samplingPriority = DD_TRACE_PRIORITY_SAMPLING_USER_REJECT;
echo 'manual reject: ', generated_trace_flags(), PHP_EOL;

DDTrace\close_span();
?>
--EXPECT--
local keep: 03
local reject: 02
manual keep: 01
manual reject: 00
