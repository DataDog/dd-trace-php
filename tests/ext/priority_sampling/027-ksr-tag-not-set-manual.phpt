--TEST--
_dd.p.ksr propagated tag is NOT set for manual sampling
--ENV--
DD_TRACE_SAMPLE_RATE=1
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php
$root = \DDTrace\root_span();
$root->meta["manual.keep"] = true;

\DDTrace\get_priority_sampling();

if (!isset($root->metrics["_dd.rule_psr"])) {
    echo "No rule_psr OK\n";
} else {
    echo "rule_psr unexpectedly set\n";
}

echo "_dd.p.ksr = ", isset($root->meta["_dd.p.ksr"]) ? $root->meta["_dd.p.ksr"] : "-", "\n";
echo "_dd.p.dm = {$root->meta["_dd.p.dm"]}\n";
?>
--EXPECT--
No rule_psr OK
_dd.p.ksr = -
_dd.p.dm = -4
