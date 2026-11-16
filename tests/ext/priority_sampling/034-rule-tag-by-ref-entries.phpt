--TEST--
priority_sampling tag rules read entries left by by-ref uses of $meta: a reference through, a null entry like an explicit null
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=1
--SKIPIF--
<?php
if (getenv("USE_ZEND_ALLOC") === "0" && !getenv("SKIP_ASAN")) {
    die("skip: test will show memory errors under valgrind where PCRE is built without valgrind support");
}
?>
--FILE--
<?php
function touch_by_ref(&$var) {
}

function set_by_ref(&$var, $value) {
    $var = $value;
}

function check($root, $tag, $pattern) {
    ini_set("datadog.trace.sampling_rules", '[{"tags":{"' . $tag . '":"' . $pattern . '"},"sample_rate":0.7},{"sample_rate":0.3}]');
    \DDTrace\get_priority_sampling();
    echo "'$pattern' ", $root->metrics["_dd.rule_psr"] == 0.7 ? "matches" : "doesn't match", " $tag\n";
}

$root = \DDTrace\root_span();
set_by_ref($root->meta['tag'], 'foo'); // leaves a reference
check($root, 'tag', 'foo');
check($root, 'tag', 'bar');

touch_by_ref($root->meta['untouched']); // leaves a null entry: it matches as an explicit null does (021)
check($root, 'untouched', '*');
check($root, 'untouched', '?');
?>
--EXPECT--
'foo' matches tag
'bar' doesn't match tag
'*' matches untouched
'?' doesn't match untouched
