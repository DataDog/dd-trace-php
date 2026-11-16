--TEST--
priority_sampling with manual.drop
--ENV--
DD_TRACE_SAMPLE_RATE=0
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php

$root = \DDTrace\root_span();
$root->meta["manual.drop"] = true;

if (!isset($root->metrics["_dd.rule_psr"]) && \DDTrace\get_priority_sampling() == \DD_TRACE_PRIORITY_SAMPLING_USER_REJECT) {
    echo "OK\n";
} else {
    echo "metrics[_dd.rule_psr] = {$root->metrics["_dd.rule_psr"]}\n";
}

echo "_dd.p.dm = ", isset($root->meta["_dd.p.dm"]) ? $root->meta["_dd.p.dm"] : "-", "\n";

?>
--EXPECT--
OK
_dd.p.dm = -
