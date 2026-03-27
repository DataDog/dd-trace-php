--TEST--
_dd.p.ksr rounds rate below 6-decimal precision to 0
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.0000001}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php
$root = \DDTrace\root_span();

\DDTrace\get_priority_sampling();

$ksr = isset($root->meta["_dd.p.ksr"]) ? $root->meta["_dd.p.ksr"] : "-";
echo "_dd.p.ksr = ", $ksr, "\n";
// 0.0000001 rounds to 0 at 6 decimal places
echo "no_sci_notation = ", (strpos($ksr, 'e') === false) ? "true" : "false", "\n";
?>
--EXPECT--
_dd.p.ksr = 0
no_sci_notation = true
