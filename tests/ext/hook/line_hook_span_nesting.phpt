--TEST--
Line spans nest correctly with shared begin sites and function hooks installed in either order
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

function nested_ranges() {
    $inner = DDTrace\active_span();
    $outer = DDTrace\active_span();
    return [$inner, $outer];
}
function guarded_exit($throw) {
    if ($throw) throw new RuntimeException('escape');
    return DDTrace\active_span();
}
$root = DDTrace\start_span();
$line = (new ReflectionFunction('nested_ranges'))->getStartLine() + 1;
$inner = $outer = null;
$a = DDTrace\install_line_hook(__FILE__, $line, function ($h) use (&$inner) { $inner = $h->span(); });
$b = DDTrace\install_line_hook(__FILE__, $line, function ($h) use (&$outer) { $outer = $h->span(); }, $line + 1);
list($inside, $outside) = nested_ranges();
verify($inner && $outer && $inside === $inner && $outside === $outer, 'outer opens first even on the first execution');
verify($inner->parent === $outer && $outer->parent === $root, 'nested parenting');
verify($inner->getDuration() > 0 && $outer->getDuration() > 0 && DDTrace\active_span() === $root, 'nested closing');
DDTrace\remove_hook($a);
DDTrace\remove_hook($b);
$line = (new ReflectionFunction('guarded_exit'))->getStartLine() + 1;
$end = (new ReflectionFunction('guarded_exit'))->getEndLine() + 1;
foreach ([false, true] as $functionFirst) {
    $lineSpan = $functionSpan = null;
    $lineEnd = $functionEnd = 0;
    $installFunction = function () use (&$functionSpan, &$functionEnd, &$lineSpan) {
        return DDTrace\install_hook('guarded_exit', function ($h) use (&$functionSpan) { $functionSpan = $h->span(); }, function ($h) use (&$functionEnd, &$lineSpan) {
            verify($lineSpan && $lineSpan->getDuration() > 0, 'line closed before function end callback');
            verify(DDTrace\active_span() === $h->span(), 'function end has its own active span');
            ++$functionEnd;
        });
    };
    if ($functionFirst) $f = $installFunction();
    $l = DDTrace\install_line_hook(__FILE__, $line, function ($h) use (&$lineSpan) { $lineSpan = $h->span(); }, $end, function () use (&$lineEnd) { ++$lineEnd; });
    if (!$functionFirst) $f = $installFunction();
    foreach ([false, true] as $throw) {
        try { guarded_exit($throw); } catch (RuntimeException $e) {}
        verify($lineSpan->parent === $functionSpan && $functionSpan->parent === $root, 'function/line parenting');
        verify($lineSpan->getDuration() > 0 && $functionSpan->getDuration() > 0 && DDTrace\active_span() === $root, 'both spans closed');
    }
    verify($lineEnd === 2 && $functionEnd === 2, 'ends delivered once');
    DDTrace\remove_hook($l);
    DDTrace\remove_hook($f);
}
DDTrace\close_span();
verify(count(dd_trace_serialize_closed_spans()) === 11, 'all nested spans serialized');

echo "OK\n";
?>
--EXPECT--
OK
