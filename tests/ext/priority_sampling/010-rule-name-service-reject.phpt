--TEST--
priority_sampling rule with name and service reject
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.3, "name": "no.*match", "service": "no.*match"}, {"sample_rate": 0.7}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--SKIPIF--
<?php
if (getenv("USE_ZEND_ALLOC") === "0") {
	die("skip: test will show memory errors under valgrind where PCRE is built without valgrind support");
}
?>
--FILE--
<?php
$root = \DDTrace\root_span();
$root->name = "fooname";
$root->service = "barservice";

\DDTrace\get_priority_sampling();

if ($root->metrics["_dd.rule_psr"] == 0.7) {
    echo "Rule OK";
} else {
    var_dump($root->metrics);
}
?>
--EXPECT--
Rule OK
