--TEST--
priority_sampling tag rules match keys aliasing span properties (component, span.kind)
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.9, "tags": {"component": "my-component", "span.kind": "client"}},{"sample_rate": 0.7, "tags": {"component": "my-*"}},{"sample_rate": 0.3, "tags": {"tag": "foo"}}]
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

function check($root, $expected) {
    \DDTrace\get_priority_sampling();
    $rate = $root->metrics["_dd.rule_psr"] ?? null;
    echo $rate == $expected ? "Rule OK\n" : "Rule mismatch: " . var_export($rate, true) . "\n";
}

$root->meta["tag"] = "foo";
check($root, 0.3);

$root->meta["component"] = "my-component";
check($root, 0.7);

$root->meta["span.kind"] = "client";
check($root, 0.9);

echo "_dd.p.dm = ", $root->meta["_dd.p.dm"] ?? "-", "\n";
?>
--EXPECT--
Rule OK
Rule OK
Rule OK
_dd.p.dm = -3
