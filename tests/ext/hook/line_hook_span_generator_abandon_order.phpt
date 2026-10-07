--TEST--
Abandoned generators close line spans before function end hooks in either installation order
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
function verify($ok, $label) { if (!$ok) echo "FAIL: $label\n"; }

function abandoned_generator() {
    yield 1;
    yield 2;
}
$root = DDTrace\start_span();
$ref = new ReflectionFunction('abandoned_generator');
foreach ([false, true] as $paired) {
    foreach ([false, true] as $functionFirst) {
        $span = null;
        $lineEnds = $functionEnds = 0;
        $installFunction = function () use (&$span, &$lineEnds, &$functionEnds, $paired) {
            return DDTrace\install_hook('abandoned_generator', null, function () use (&$span, &$lineEnds, &$functionEnds, $paired) {
                verify($span->getDuration() > 0, 'line span closed before function end');
                verify($lineEnds === ($paired ? 1 : 0), 'line end delivered before function end');
                ++$functionEnds;
            });
        };
        if ($functionFirst) $functionId = $installFunction();
        $lineId = DDTrace\install_line_hook(__FILE__, $ref->getStartLine() + 1,
            function ($h) use (&$span) { $span = $h->span(); }, $ref->getEndLine() + 1,
            $paired ? function () use (&$lineEnds) { ++$lineEnds; } : null);
        if (!$functionFirst) $functionId = $installFunction();
        $g = abandoned_generator();
        $g->current();
        unset($g);
        gc_collect_cycles();
        verify($functionEnds === 1 && $lineEnds === ($paired ? 1 : 0), 'ends delivered once');
        verify(DDTrace\active_span() === $root, 'caller restored');
        DDTrace\remove_hook($lineId);
        DDTrace\remove_hook($functionId);
    }
}
DDTrace\close_span();
verify(count(dd_trace_serialize_closed_spans()) === 5, 'all spans serialized once');
echo "OK\n";
?>
--EXPECT--
OK
