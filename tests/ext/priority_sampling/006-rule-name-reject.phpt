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

if ($root->metrics["_dd.rule_psr"] != 0.3) {
    echo "Rule OK";
} else {
    var_dump($root->metrics);
}
?>
--EXPECT--
Rule OK
