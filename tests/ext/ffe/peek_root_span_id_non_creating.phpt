--TEST--
FFE span enrichment: DDTrace\Internal\peek_root_span_id() is non-creating and stable/unique per root
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %r");
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLI_ENABLED=1
--FILE--
<?php
function show($label, $value) {
    echo $label . '=' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
}

// The non-creating root accessor used by APM feature-flag span enrichment must
// exist (replaces \DDTrace\root_span(), which creates an autoroot span as a
// side effect).
show('peek_fn_exists', function_exists('DDTrace\\Internal\\peek_root_span_id'));

// With DD_TRACE_GENERATE_ROOT_SPAN=0 there is no root span yet. peek_root_span_id()
// must return null AND must NOT create a root span as a side effect — proven by
// active_span() still being null afterwards. (\DDTrace\root_span() would have
// fabricated one here; that is the bug the review flagged.)
show('peek_before', \DDTrace\Internal\peek_root_span_id());
show('active_span_still_null_after_peek', \DDTrace\active_span() === null);

// Now open a real span. peek_root_span_id() must return an identity that is
// stable for the lifetime of that root (not derived from a recyclable zend
// object handle, which a dropped-and-replaced root could alias).
$span = \DDTrace\start_span();
$peeked = \DDTrace\Internal\peek_root_span_id();
$peekedAgain = \DDTrace\Internal\peek_root_span_id();
show('peek_is_int', is_int($peeked));
show('peek_stable_within_same_root', $peeked === $peekedAgain);
\DDTrace\close_span();

// A second, distinct root must get a distinct identity.
$span2 = \DDTrace\start_span();
$peeked2 = \DDTrace\Internal\peek_root_span_id();
show('peek_distinct_across_roots', $peeked !== $peeked2);
\DDTrace\close_span();

?>
--EXPECT--
peek_fn_exists=true
peek_before=null
active_span_still_null_after_peek=true
peek_is_int=true
peek_stable_within_same_root=true
peek_distinct_across_roots=true
