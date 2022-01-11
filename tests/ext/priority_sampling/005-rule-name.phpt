--TEST--
priority_sampling rule with name match
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.7, "name": "bar"},{"sample_rate": 0.3, "name": "foo"}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php
$root = \DDTrace\root_span();
$root->name = "fooname";

\DDTrace\get_priority_sampling();

if ($root->metrics["_dd.rule_psr"] == 0.3) {
    echo "Rule OK\n";
} else {
    var_dump($root->metrics);
}
echo "_dd.p.upstream_services = {$root->meta["_dd.p.upstream_services"]}\n";
?>
--EXPECTREGEX--
Rule OK
_dd.p.upstream_services = MDA1LXJ1bGUtbmFtZS5waHA\|(-1|2)\|3\|0.300
