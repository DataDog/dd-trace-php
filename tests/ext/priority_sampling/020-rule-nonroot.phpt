--TEST--
priority_sampling rule with match on non-root spans
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.9, "tags": {"end": "true"}},{"sample_rate": 0.7, "service": "bar", "target_span": "any"},{"sample_rate": 0.3, "service": "foo"}]
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
$root->traceId = str_repeat("1", 32);

var_dump(\DDTrace\get_priority_sampling() == \DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP);

if ($root->metrics["_dd.agent_psr"] == 1) {
    echo "Rule OK\n";
} else {
    var_dump($root->metrics);
}

echo "_dd.p.dm = ", isset($root->meta["_dd.p.dm"]) ? $root->meta["_dd.p.dm"] : "-", "\n";

$root->service = "foo";

var_dump(\DDTrace\get_priority_sampling() == \DD_TRACE_PRIORITY_SAMPLING_USER_KEEP);

if (!isset($root->metrics["_dd.agent_psr"]) && $root->metrics["_dd.rule_psr"] == 0.3) {
    echo "Rule OK\n";
} else {
    var_dump($root->metrics);
}

echo "_dd.p.dm = ", isset($root->meta["_dd.p.dm"]) ? $root->meta["_dd.p.dm"] : "-", "\n";

$child = \DDTrace\start_span();

$child->service = "bar";

var_dump(\DDTrace\get_priority_sampling() == \DD_TRACE_PRIORITY_SAMPLING_USER_KEEP);

if (!isset($root->metrics["_dd.agent_psr"]) && $root->metrics["_dd.rule_psr"] == 0.7) {
    echo "Rule OK\n";
} else {
    var_dump($root->metrics);
}

echo "_dd.p.dm = ", isset($root->meta["_dd.p.dm"]) ? $root->meta["_dd.p.dm"] : "-", "\n";

\DDTrace\close_span();

var_dump(\DDTrace\get_priority_sampling() == \DD_TRACE_PRIORITY_SAMPLING_USER_KEEP);

if (!isset($root->metrics["_dd.agent_psr"]) && $root->metrics["_dd.rule_psr"] == 0.7) {
    echo "Rule OK\n";
} else {
    var_dump($root->metrics);
}

echo "_dd.p.dm = ", isset($root->meta["_dd.p.dm"]) ? $root->meta["_dd.p.dm"] : "-", "\n";

$root->meta["end"] = "true";

var_dump(\DDTrace\get_priority_sampling() == \DD_TRACE_PRIORITY_SAMPLING_USER_KEEP);

if (!isset($root->metrics["_dd.agent_psr"]) && $root->metrics["_dd.rule_psr"] == 0.9) {
    echo "Rule OK\n";
} else {
    var_dump($root->metrics);
}

?>
--EXPECTF--
bool(true)
Rule OK
_dd.p.dm = -0
bool(true)
Rule OK
_dd.p.dm = -3
bool(true)
Rule OK
_dd.p.dm = -3
bool(true)
Rule OK
_dd.p.dm = -3
bool(true)
Rule OK
