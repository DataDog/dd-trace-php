--TEST--
_dd.p.ksr propagated tag formats rate with up to 6 significant digits and no trailing zeros
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.7654321}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php
$root = \DDTrace\root_span();

\DDTrace\get_priority_sampling();

echo "_dd.p.ksr = ", isset($root->meta["_dd.p.ksr"]) ? $root->meta["_dd.p.ksr"] : "-", "\n";
// Verify it's a string in meta, not metrics
echo "is_string = ", is_string($root->meta["_dd.p.ksr"] ?? null) ? "true" : "false", "\n";
?>
--EXPECT--
_dd.p.ksr = 0.765432
is_string = true
