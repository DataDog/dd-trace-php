--TEST--
priority_sampling rule with name reject
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.3, "name": "bar"}]
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_TRACE_PROPAGATE_SERVICE=1
--FILE--
<?php
$root = \DDTrace\root_span();
$root->name = "fooname";

\DDTrace\get_priority_sampling();

if ($root->metrics["_dd.rule_psr"] != 0.3) {
    echo "Rule OK\n";
} else {
    var_dump($root->metrics);
}
echo "_dd.dm.service_hash = {$root->meta["_dd.dm.service_hash"]}\n";
echo "_dd.p.dm = {$root->meta["_dd.p.dm"]}\n";
?>
--EXPECT--
Rule OK
_dd.dm.service_hash = 78d5c41f07
_dd.p.dm = 78d5c41f07-1
