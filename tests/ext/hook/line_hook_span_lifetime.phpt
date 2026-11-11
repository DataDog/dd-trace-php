--TEST--
LineHookData dummy spans, explicit parents, cloning, subclasses, and cyclic references are safe
--SKIPIF--
<?php if (PHP_VERSION_ID < 70400) die('skip requires WeakReference'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
function verify($ok, $label) {
    if (!$ok) echo "FAIL: $label\n";
}

class MyLineData extends DDTrace\LineHookData { public $extra = 'preserved'; }
$h = new MyLineData();
$dummy = $h->span();
verify($dummy->getStartTime() === 0 && $h->span() === $dummy && $h->unlimitedSpan() === $dummy, 'standalone span is cached and inactive');
$clone = clone $h;
verify($clone->extra === 'preserved' && $clone->span() !== $dummy, 'clone has independent private state');
foreach ([42, new stdClass()] as $invalid) {
    try { $h->span($invalid); echo "FAIL: accepted invalid parent\n"; } catch (TypeError $e) {}
    try { $h->unlimitedSpan($invalid); echo "FAIL: accepted invalid unlimited parent\n"; } catch (TypeError $e) {}
}
try { $h->span(null, null); echo "FAIL: accepted extra argument\n"; } catch (TypeError $e) {}
$weak = WeakReference::create($h);
$dummy->meta['hook'] = $h;
unset($dummy, $h);
gc_collect_cycles();
verify($weak->get() === null, 'hidden span reference participates in GC');
function parent_work() {
    return DDTrace\active_span();
}
$root = DDTrace\start_span();
$rootStack = DDTrace\active_stack();
$parentStack = DDTrace\create_stack();
$parent = DDTrace\start_span();
DDTrace\switch_stack($rootStack);
$line = (new ReflectionFunction('parent_work'))->getStartLine() + 1;
$seen = 0;
foreach ([$parent, $parentStack, null] as $arg) {
    $id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use ($arg, &$seen) {
        ++$seen;
        $span = $h->span($arg);
        $copy = clone $h;
        verify($copy->span()->getStartTime() === 0 && DDTrace\active_span() === $span, 'active clone does not inherit the range');
    });
    $span = parent_work();
    verify($span->parent === ($arg ? $parent : $root), 'explicit parent chosen');
    verify($span->stack === ($arg ? $parentStack : $rootStack), 'parent stack chosen');
    verify($span->getDuration() > 0 && DDTrace\active_stack() === $rootStack, 'prior stack restored');
    DDTrace\remove_hook($id);
}
$id = DDTrace\install_line_hook(__FILE__, $line, null, $line, function ($h) use (&$seen, $root) {
    ++$seen;
    verify($h->span()->getStartTime() === 0 && DDTrace\active_span() === $root, 'end-only span is inactive');
});
parent_work();
DDTrace\remove_hook($id);
verify($seen === 4, 'expected callbacks ran');
DDTrace\switch_stack($parentStack);
DDTrace\close_span();
DDTrace\switch_stack($rootStack);
DDTrace\close_span();
verify(count(dd_trace_serialize_closed_spans()) === 5, 'dummy spans are not serialized');

echo "OK\n";
?>
--EXPECT--
OK
