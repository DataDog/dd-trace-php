--TEST--
Invalid rule json: default sampling rate applies
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.3, "tags": {"test": "foo", "value": "bar"}]
DD_TRACE_GENERATE_ROOT_SPAN=1
--SKIPIF--
<?php
if (getenv("USE_ZEND_ALLOC") === "0" && !getenv("SKIP_ASAN")) {
    die("skip: test will show memory errors under valgrind where PCRE is built without valgrind support");
}
?>
--FILE--
<?php

\DDTrace\get_priority_sampling();

$root = \DDTrace\root_span();
if (($root->metrics["_dd.rule_psr"] ?? 0) != 0.3 && $root->metrics["_dd.agent_psr"] == 1) {
    echo "Rule OK\n";
} else {
    var_dump($root->metrics);
}

?>
--EXPECT--
Rule OK
