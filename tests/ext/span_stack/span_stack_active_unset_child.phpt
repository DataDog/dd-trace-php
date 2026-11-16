--TEST--
SpanStack active unset does not corrupt child span opening
--DESCRIPTION--
DDTrace\SpanStack::$active aliases a C raw span pointer. Unsetting it must be
rejected instead of dropping the active span zval while leaving a stale internal
parent pointer behind.
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

use DDTrace\SpanData;

function span_stack_active_unset_child() {}

\DDTrace\trace_function('span_stack_active_unset_child', static function (SpanData $span) {
    $span->name = 'span_stack_active_unset_child';
});

$root = \DDTrace\start_span();
$root->service = 'parent-service';
$stack = \DDTrace\active_stack();

try {
    unset($stack->active);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

unset($root);
gc_collect_cycles();

$junk = [];
for ($i = 0; $i < 10000; $i++) {
    $junk[] = new stdClass();
}

span_stack_active_unset_child();
echo "ok\n";

?>
--EXPECT--
Cannot unset readonly property DDTrace\SpanStack::$active
ok
