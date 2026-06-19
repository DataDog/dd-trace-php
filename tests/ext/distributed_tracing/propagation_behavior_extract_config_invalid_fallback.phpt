--TEST--
DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT: invalid value falls back to continue
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT=invalid_value
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers([
    "x-datadog-trace-id" => 42,
    "x-datadog-parent-id" => 10,
]);

$span = DDTrace\start_span();
$root = DDTrace\root_span();

// invalid value falls back to default (continue): inherits upstream trace id
echo "same_as_upstream: " . ($root->traceId === "0000000000000000000000000000002a" ? "yes" : "no") . "\n";

DDTrace\close_span();
?>
--EXPECT--
same_as_upstream: yes
