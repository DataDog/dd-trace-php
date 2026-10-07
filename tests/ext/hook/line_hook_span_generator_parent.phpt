--TEST--
Line spans with explicit generator parents stay detached from their parents across yields
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
function verify($ok, $label) { if (!$ok) echo "FAIL: $label\n"; }

function parented_generator() {
    yield DDTrace\active_span();
    yield DDTrace\active_span();
}
$root = DDTrace\start_span();
$rootStack = DDTrace\active_stack();
$parentStack = DDTrace\create_stack();
$parent = DDTrace\start_span();
DDTrace\switch_stack($rootStack);
$line = (new ReflectionFunction('parented_generator'))->getStartLine() + 1;
$end = (new ReflectionFunction('parented_generator'))->getEndLine();
foreach ([$root, $rootStack, $parent, $parentStack] as $arg) {
    $expectedParent = ($arg === $root || $arg === $rootStack) ? $root : $parent;
    $id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use ($arg) { $h->span($arg); }, $end);
    foreach ([false, true] as $abandon) {
        $g = parented_generator();
        $span = $g->current();
        verify($span->parent === $expectedParent && $span->stack !== $expectedParent->stack, 'explicit parent with isolated stack');
        verify(DDTrace\active_span() === $root, 'yield restores caller');
        if (!$abandon) {
            $caller = DDTrace\start_span();
            $g->next();
            verify($g->current() === $span && DDTrace\active_span() === $caller, 'resumption preserves caller context');
            $g->next();
            verify(DDTrace\active_span() === $caller, 'completion restores caller');
            DDTrace\close_span();
        }
        unset($g);
        gc_collect_cycles();
        verify($span->getDuration() > 0 && DDTrace\active_span() === $root, 'completion or abandonment closes span');
    }
    DDTrace\remove_hook($id);
}
DDTrace\switch_stack($parentStack);
verify(DDTrace\active_span() === $parent, 'explicit parent stack remains intact');
DDTrace\close_span();
DDTrace\switch_stack($rootStack);
DDTrace\close_span();
verify(count(dd_trace_serialize_closed_spans()) === 14, 'all generator spans serialized');
echo "OK\n";
?>
--EXPECT--
OK
