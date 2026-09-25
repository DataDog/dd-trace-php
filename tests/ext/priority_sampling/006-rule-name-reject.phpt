--TEST--
priority_sampling rule with name reject
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.3, "name": "bar"}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php
$root = \DDTrace\root_span();
$root->name = "fooname";

\DDTrace\get_priority_sampling();

if ($root->attributes["_dd.rule_psr"] ?? -1 != 0.3 && $root->attributes["_dd.agent_psr"] == 1) {
    echo "Rule OK\n";
} else {
    var_dump($root->attributes);
}
echo "_dd.p.dm = {$root->attributes["_dd.p.dm"]}\n";
?>
--EXPECT--
Rule OK
_dd.p.dm = -0
