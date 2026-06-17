--TEST--
DD_TRACE_PROPAGATION_BEHAVIOR_EXTRACT config parsing: case-insensitive, invalid falls back to continue
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

// Helper: return trace_id after consuming upstream context with a given config value
function check_behavior(string $config_value): string {
    ini_set('datadog.trace.propagation_behavior_extract', $config_value);

    DDTrace\consume_distributed_tracing_headers([
        "x-datadog-trace-id" => 42,
        "x-datadog-parent-id" => 10,
    ]);

    $span = DDTrace\start_span();
    $result = DDTrace\root_span()->traceId === "0000000000000000000000000000002a" ? "continue" : "restart_or_ignore";
    DDTrace\close_span();

    return $result;
}

// Lowercase values
echo "continue: "       . check_behavior("continue") . "\n";
echo "restart: "        . check_behavior("restart") . "\n";
echo "ignore: "         . check_behavior("ignore") . "\n";

// Case-insensitive
echo "CONTINUE: "       . check_behavior("CONTINUE") . "\n";
echo "RESTART: "        . check_behavior("RESTART") . "\n";
echo "Ignore: "         . check_behavior("Ignore") . "\n";

// Invalid value falls back to default (continue)
echo "invalid: "        . check_behavior("invalid_value") . "\n";

?>
--EXPECT--
continue: continue
restart: restart_or_ignore
ignore: restart_or_ignore
CONTINUE: continue
RESTART: restart_or_ignore
Ignore: restart_or_ignore
invalid: continue
