--TEST--
SpanStack active references do not corrupt child span opening
--DESCRIPTION--
DDTrace\SpanStack::$active is exposed as a normal PHP property, but the C span
stack also aliases the same zval slot as a raw ddtrace_span_properties pointer.
If userland acquires that property by reference, the slot becomes IS_REFERENCE.
Opening a later child span must not treat the zend_reference container as a
SpanData pointer while inheriting parent state.

This reproduces the parent-service corruption path where the next span can crash
while reading or copying properties from what should be the active parent span.
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

function touch_ref(&$value) {}

$root = \DDTrace\start_span();
$root->service = "parent-service";
touch_ref(\DDTrace\active_stack()->active);
$child = \DDTrace\start_span();

var_dump($child->parent === $root);
var_dump($child->service);
echo "ok\n";

?>
--EXPECT--
bool(true)
string(14) "parent-service"
ok
