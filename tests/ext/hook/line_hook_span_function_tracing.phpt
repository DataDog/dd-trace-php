--TEST--
Line spans close before enclosing legacy and attribute function spans
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
function verify($ok, $label) { if (!$ok) echo "FAIL: $label\n"; }

function legacy_range($throw) {
    if ($throw) throw new RuntimeException('escape');
    return DDTrace\active_span();
}
#[DDTrace\Trace]
function attribute_range($throw) {
    if ($throw) throw new RuntimeException('escape');
    return DDTrace\active_span();
}
$root = DDTrace\start_span();
foreach (['legacy_range', 'attribute_range'] as $function) {
    if ($function === 'attribute_range' && PHP_VERSION_ID < 80000) continue;
    $reflection = new ReflectionFunction($function);
    foreach ([false, true] as $functionFirst) {
        $lineSpan = $functionSpan = null;
        $functionEnds = 0;
        $installFunction = function () use ($function, &$lineSpan, &$functionEnds) {
            if ($function === 'legacy_range') {
                DDTrace\trace_function($function, function ($span) use (&$lineSpan, &$functionEnds) {
                    verify($lineSpan->getDuration() > 0 && DDTrace\active_span() === $span, 'line closed before legacy end');
                    ++$functionEnds;
                });
            }
        };
        if ($functionFirst) $installFunction();
        $id = DDTrace\install_line_hook(__FILE__, $reflection->getStartLine() + 1, function ($h) use (&$lineSpan, &$functionSpan) {
            $functionSpan = DDTrace\active_span();
            $lineSpan = $h->span();
        }, $reflection->getEndLine() + 1);
        if (!$functionFirst) $installFunction();
        foreach ([false, true] as $throw) {
            try { $function($throw); } catch (RuntimeException $e) {}
            verify($lineSpan->parent === $functionSpan && $functionSpan->parent === $root, 'line/function parenting');
            verify($lineSpan->getDuration() > 0 && $functionSpan->getDuration() > 0, 'both spans closed');
            verify(DDTrace\active_span() === $root, 'caller restored');
        }
        if ($function === 'legacy_range') {
            verify($functionEnds === 2, 'legacy callbacks delivered once');
            dd_untrace($function);
        }
        DDTrace\remove_hook($id);
    }
}
DDTrace\close_span();
verify(count(dd_trace_serialize_closed_spans()) === (PHP_VERSION_ID < 80000 ? 9 : 17), 'all spans serialized');
echo "OK\n";
?>
--EXPECT--
OK
