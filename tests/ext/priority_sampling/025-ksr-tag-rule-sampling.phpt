--TEST--
_dd.p.ksr propagated tag is set for rule-based sampling
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.3}]
DD_TRACE_GENERATE_ROOT_SPAN=1
LOCALE=de_DE
--FILE--
<?php
$root = \DDTrace\root_span();

\DDTrace\get_priority_sampling();

if ($root->attributes["_dd.rule_psr"] == 0.3) {
    echo "Rule OK\n";
} else {
    var_dump($root->attributes);
}

echo "_dd.p.ksr = ", isset($root->attributes["_dd.p.ksr"]) ? $root->attributes["_dd.p.ksr"] : "-", "\n";
echo "_dd.p.dm = ", isset($root->attributes["_dd.p.dm"]) ? $root->attributes["_dd.p.dm"] : "-", "\n";
?>
--EXPECTREGEX--
Rule OK
_dd.p.ksr = 0.3
_dd.p.dm = (-3|-)
