--TEST--
_dd.p.ksr rounds small rates correctly at 6-decimal boundary
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.00000051}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php
$root = \DDTrace\root_span();

\DDTrace\get_priority_sampling();

$ksr = isset($root->meta["_dd.p.ksr"]) ? $root->meta["_dd.p.ksr"] : "-";
echo "_dd.p.ksr = ", $ksr, "\n";
// 0.00000051 rounds up to 0.000001 at 6 decimal places
echo "no_sci_notation = ", (strpos($ksr, 'e') === false) ? "true" : "false", "\n";
?>
--EXPECT--
_dd.p.ksr = 0.000001
no_sci_notation = true
