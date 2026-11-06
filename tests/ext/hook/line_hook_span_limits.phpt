--TEST--
LineHookData span honors limits while unlimitedSpan bypasses them, and neither enables disabled tracing
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_TRACE_SPANS_LIMIT=1
--FILE--
<?php
function verify($ok, $label) {
    if (!$ok) echo "FAIL: $label\n";
}

function limited_work() {
    return DDTrace\active_span();
}
$root = DDTrace\start_span();
verify(dd_trace_tracer_is_limited(), 'limit reached');
$line = (new ReflectionFunction('limited_work'))->getStartLine() + 1;
$seen = [];
foreach (['span', 'unlimitedSpan'] as $method) {
    $id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use ($method, &$seen) { $seen[] = $h->$method(); });
    $inside = limited_work();
    $span = end($seen);
    verify(($method === 'span' ? $span->getStartTime() === 0 && $inside === $root : $span === $inside && $span->getDuration() > 0), 'limit policy for ' . $method);
    verify(DDTrace\active_span() === $root, 'caller restored');
    DDTrace\remove_hook($id);
}
verify(count($seen) === 2, 'both callbacks ran');
ini_set('datadog.trace.enabled', '0');
$h = new DDTrace\LineHookData();
verify($h->unlimitedSpan()->getStartTime() === 0, 'unlimited does not enable tracing');

echo "OK\n";
?>
--EXPECT--
OK
