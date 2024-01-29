--TEST--
priority_sampling rule with tag reject
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.3, "tags": {"missing": "bar"}}, {"sample_rate": 0.4, "tags": {"foo": "bar"}}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--SKIPIF--
<?php
if (getenv("USE_ZEND_ALLOC") === "0" && !getenv("SKIP_ASAN")) {
    die("skip: test will show memory errors under valgrind where PCRE is built without valgrind support");
}
?>
--FILE--
<?php
$root = \DDTrace\root_span();
$root->meta["foo"] = "different";

\DDTrace\get_priority_sampling();

if (($root->metrics["_dd.rule_psr"] ?? 0) != 0.3 && $root->metrics["_dd.agent_psr"] == 1) {
    echo "Rule OK\n";
} else {
    var_dump($root->metrics);
}
echo "_dd.p.dm = {$root->meta["_dd.p.dm"]}\n";
?>
--EXPECT--
Rule OK
_dd.p.dm = -0
