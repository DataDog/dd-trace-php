--TEST--
Distributed tracing headers can be set
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php
dd_trace_distributed_tracing_headers([
    'header: one',
    'x-header: two',
    'x-datadog-trace-id: 1234',
    'x-datadog-parent-id: 1337', // Should be replaced by active span ID
    'dd: bb',
    'x-datadog-sampling-priority: 0.5',
]);
echo 'Done.' . PHP_EOL;
?>
--EXPECT--
Done.
