--TEST--
manual.keep will overwrite a rejected distributed sampling decision
--ENV--
DD_TRACE_SAMPLE_RATE=0
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php

$root = \DDTrace\root_span();

DDTrace\consume_distributed_tracing_headers(function ($header) {
    return [
            "x-datadog-trace-id" => 42,
            "x-datadog-parent-id" => 10,
            "x-datadog-sampling-priority" => -1,
        ][$header] ?? null;
});

$root->meta["manual.keep"] = true;

if (!isset($root->metrics["_dd.rule_psr"]) && \DDTrace\get_priority_sampling() == \DD_TRACE_PRIORITY_SAMPLING_USER_KEEP) {
    echo "OK\n";
} else {
    echo "metrics[_dd.rule_psr] = {$root->metrics["_dd.rule_psr"]}\n";
}

echo "_dd.p.dm = {$root->meta["_dd.p.dm"]}\n";

?>
--EXPECT--
OK
_dd.p.dm = -4
