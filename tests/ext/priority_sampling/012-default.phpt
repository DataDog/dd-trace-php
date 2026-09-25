--TEST--
priority_sampling default decision retained
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 1}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php
\DDTrace\set_priority_sampling(DD_TRACE_PRIORITY_SAMPLING_UNKNOWN, true);

\DDTrace\get_priority_sampling(true);

$root = \DDTrace\root_span();

if (!isset($root->attributes["_dd.rule_psr"])) {
    echo "OK\n";
} else {
    echo "metrics[_dd.rule_psr] = {$root->attributes["_dd.rule_psr"]}\n";
}

if (isset($root->attributes["_dd.p.dm"])) {
    echo "_dd.p.dm = {$root->attributes["_dd.p.dm"]}\n";
}
?>
--EXPECT--
OK
