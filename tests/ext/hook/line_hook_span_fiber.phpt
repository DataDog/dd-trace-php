--TEST--
Line span parenting survives interleaved fibers and fiber abandonment
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires Fibers'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
function verify($ok, $label) { if (!$ok) echo "FAIL: $label\n"; }

function fiber_range() {
    $span = DDTrace\active_span();
    Fiber::suspend($span);
    verify(DDTrace\active_span() === $span, 'fiber resumes with its span');
    return $span;
}
$root = DDTrace\start_span();
$line = (new ReflectionFunction('fiber_range'))->getStartLine() + 1;
$end = (new ReflectionFunction('fiber_range'))->getEndLine();
$spans = [];
$ends = 0;
$id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use (&$spans) { $spans[] = $h->span(); }, $end, function ($h) use (&$ends) {
    verify($h->span()->getDuration() > 0, 'fiber span stopped before end');
    ++$ends;
});
$a = new Fiber('fiber_range');
$b = new Fiber('fiber_range');
$sa = $a->start();
$sb = $b->start();
verify(count($spans) === 2 && $sa !== $sb && $sa->parent === $root && $sb->parent === $root, 'fiber spans are siblings');
verify(DDTrace\active_span() === $root, 'suspended fibers restore caller');
$a->resume();
verify($a->getReturn() === $sa && $sa->getDuration() > 0, 'first fiber completed');
unset($b);
gc_collect_cycles();
verify($sb->getDuration() > 0 && $ends === 2 && DDTrace\active_span() === $root, 'abandoned fiber closed independently');
DDTrace\remove_hook($id);
DDTrace\close_span();
verify(count(dd_trace_serialize_closed_spans()) === 3, 'fiber spans serialized once');

echo "OK\n";
?>
--EXPECT--
OK
