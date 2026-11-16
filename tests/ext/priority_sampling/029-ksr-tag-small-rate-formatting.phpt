--TEST--
_dd.p.ksr uses decimal notation (not scientific) for very small sampling rates
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.000001}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php
$root = \DDTrace\root_span();

\DDTrace\get_priority_sampling();

$ksr = isset($root->meta["_dd.p.ksr"]) ? $root->meta["_dd.p.ksr"] : "-";
echo "_dd.p.ksr = ", $ksr, "\n";
// Must be decimal notation, not scientific (e.g. "0.000001" not "1e-06")
echo "no_sci_notation = ", (strpos($ksr, 'e') === false) ? "true" : "false", "\n";
?>
--EXPECT--
_dd.p.ksr = 0.000001
no_sci_notation = true
