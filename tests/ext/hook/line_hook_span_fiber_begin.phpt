--TEST--
Begin-only line hooks can open spans after interleaved callbacks resume in either order
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

function left_range() {
    return DDTrace\active_span();
}
function right_range() {
    return DDTrace\active_span();
}
$root = DDTrace\start_span();
foreach ([false, true] as $reverse) {
    $ids = $spans = [];
    foreach (['left_range', 'right_range'] as $function) {
        $ref = new ReflectionFunction($function);
        $ids[] = DDTrace\install_line_hook(__FILE__, $ref->getStartLine() + 1,
            function ($h) use ($function, &$spans) {
                Fiber::suspend();
                $spans[$function] = $h->span();
            }, $ref->getEndLine());
    }
    $a = new Fiber('left_range');
    $b = new Fiber('right_range');
    $a->start();
    $b->start();
    foreach ($reverse ? [$b, $a] : [$a, $b] as $fiber) $fiber->resume();
    verify($a->getReturn() === $spans['left_range'] && $b->getReturn() === $spans['right_range'], 'each range used its own span');
    foreach ($spans as $span) {
        verify($span->parent === $root && $span->getDuration() > 0, 'real span closed with the expected parent');
    }
    verify(DDTrace\active_span() === $root, 'caller restored');
    foreach ($ids as $id) DDTrace\remove_hook($id);
}
DDTrace\close_span();
verify(count(dd_trace_serialize_closed_spans()) === 5, 'all spans serialized once');
echo "OK\n";
?>
--EXPECT--
OK
