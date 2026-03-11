--TEST--
_dd.p.ksr propagated tag is NOT set for default sampling (only for explicit agent rates)
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php
$root = \DDTrace\root_span();

\DDTrace\get_priority_sampling();

if ($root->metrics["_dd.agent_psr"] === 1.0) {
    echo "Agent PSR OK\n";
} else {
    echo "Agent PSR missing\n";
}

echo "_dd.p.ksr = ", isset($root->meta["_dd.p.ksr"]) ? $root->meta["_dd.p.ksr"] : "not set", "\n";
echo "_dd.p.dm = {$root->meta["_dd.p.dm"]}\n";
?>
--EXPECT--
Agent PSR OK
_dd.p.ksr = not set
_dd.p.dm = -0
