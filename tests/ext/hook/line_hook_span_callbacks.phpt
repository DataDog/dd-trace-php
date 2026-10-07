--TEST--
Line span cleanup survives callback exceptions, false end returns, and disabling tracing mid-range
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
function verify($ok, $label) { if (!$ok) echo "FAIL: $label\n"; }

function callback_range($disable = false) {
    $span = DDTrace\active_span();
    if ($disable) ini_set('datadog.trace.enabled', '0');
    return $span;
}
$root = DDTrace\start_span();
$root->name = 'root';
$line = (new ReflectionFunction('callback_range'))->getStartLine() + 1;
$end = (new ReflectionFunction('callback_range'))->getEndLine();
$begins = $ends = 0;
$id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use (&$begins) {
    ++$begins;
    $h->span()->name = 'kept';
    throw new RuntimeException('instrumentation');
}, $end, function ($h) use (&$ends) {
    ++$ends;
    verify($h->span()->exception === null, 'callback exception does not mark application span');
    throw new RuntimeException('instrumentation end');
});
$kept = callback_range();
verify($kept->getDuration() > 0 && DDTrace\active_span() === $root, 'throwing callbacks still close span');
DDTrace\remove_hook($id);
$id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use (&$begins) {
    ++$begins;
    $h->span()->name = 'dropped';
}, $end, function () use (&$ends) { ++$ends; return false; });
callback_range();
DDTrace\remove_hook($id);
verify($begins === 2 && $ends === 2 && DDTrace\active_span() === $root, 'false end drops and restores');
DDTrace\close_span();
$names = array_column(dd_trace_serialize_closed_spans(), 'name');
sort($names);
verify($names === ['kept', 'root'], 'only retained spans serialized');
$span = null;
$id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use (&$span) { $span = $h->span(); }, $end);
callback_range(true);
verify($span instanceof DDTrace\SpanData && $span->getDuration() !== 0, 'disabling tracing safely finishes span');
DDTrace\remove_hook($id);

echo "OK\n";
?>
--EXPECT--
OK
