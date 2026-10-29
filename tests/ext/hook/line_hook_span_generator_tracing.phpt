--TEST--
Generator line spans resume inside legacy and attribute function tracing regardless of guard registration order
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
function verify($ok, $label) { if (!$ok) echo "FAIL: $label\n"; }

function traced_generator() {
    yield DDTrace\active_span();
    yield DDTrace\active_span();
}
#[DDTrace\Trace]
function attributed_generator() {
    yield DDTrace\active_span();
    yield DDTrace\active_span();
}
$root = DDTrace\start_span();
foreach (['traced_generator', 'attributed_generator'] as $function) {
    if ($function === 'attributed_generator' && PHP_VERSION_ID < 80000) continue;
    $line = (new ReflectionFunction($function))->getStartLine() + 1;
    foreach ([false, true] as $paired) {
        foreach ([false, true] as $functionFirst) {
            $span = null;
            $ends = 0;
            $install = function () use ($function) {
                if ($function !== 'traced_generator') return;
                DDTrace\trace_function($function, function ($functionSpan) {
                    verify(DDTrace\active_span() === $functionSpan, 'function callback has its own span');
                });
            };
            if ($functionFirst) $install();
            $id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use (&$span) { $span = $h->span(); }, $line + 2,
                $paired ? function () use (&$ends) { ++$ends; } : null);
            if (!$functionFirst) $install();
            $g = $function();
            verify($g->current() === $span && DDTrace\active_span() === $root, 'first yield restores caller');
            $g->next();
            verify($g->current() === $span && DDTrace\active_span() === $root, 'second resumption keeps line span active');
            $g->next();
            verify($span->getDuration() > 0 && DDTrace\active_span() === $root, 'completion closes line span and restores caller');
            verify($ends === ($paired ? 1 : 0), 'range end delivered once');
            DDTrace\remove_hook($id);
            if ($function === 'traced_generator') dd_untrace($function);
        }
    }
}
DDTrace\close_span();
verify(count(dd_trace_serialize_closed_spans()) === (PHP_VERSION_ID < 80000 ? 17 : 33), 'all range and function spans serialized');
echo "OK\n";
?>
--EXPECT--
OK
