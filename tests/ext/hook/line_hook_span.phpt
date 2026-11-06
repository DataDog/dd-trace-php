--TEST--
LineHookData spans cover the range, reuse the same object, and close before execution continues
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

function range_work() {
    $inside = DDTrace\active_span();
    usleep(1);
    return [$inside, DDTrace\active_span()];
}
$root = DDTrace\start_span();
$root->name = 'root';
$line = (new ReflectionFunction('range_work'))->getStartLine() + 1;
$spans = $data = [];
$ends = $closes = 0;
$id = DDTrace\install_line_hook(__FILE__, $line, function (DDTrace\LineHookData $h) use (&$spans, &$data, &$closes, $root, $line) {
    $span = $h->span(null);
    verify($h->span() === $span && $h->unlimitedSpan() === $span, 'same span on repeated calls');
    verify(DDTrace\active_span() === $span && $span->parent === $root, 'active child span');
    verify($span->name === __FILE__ . ':' . $line, 'default source name');
    $span->name = 'range' . (count($spans) + 1);
    $span->onClose[] = function ($closed) use (&$closes) {
        verify($closed->getDuration() > 0, 'time stopped before onClose');
        ++$closes;
    };
    $h->data = $span;
    $spans[] = $span;
    $data[] = $h;
}, $line + 1, function (DDTrace\LineHookData $h) use (&$ends) {
    verify($h->span() === $h->data, 'begin/end share the span');
    verify($h->span()->getDuration() > 0, 'time stopped before end callback');
    verify(DDTrace\active_span() === $h->span(), 'span remains active in end callback');
    ++$ends;
});
for ($i = 0; $i < 2; ++$i) {
    list($inside, $after) = range_work();
    verify(isset($spans[$i]) && $inside === $spans[$i] && $after === $root, 'range boundaries');
    verify(DDTrace\active_span() === $root, 'caller restored');
}
DDTrace\remove_hook($id);
verify(count($spans) === 2 && $ends === 2 && $closes === 2 && $spans[0] !== $spans[1], 'one span per range execution');
verify($data[0]->span()->getStartTime() === 0, 'retained HookData cannot reopen a finished range');
DDTrace\close_span();
$names = array_column(dd_trace_serialize_closed_spans(), 'name');
sort($names);
verify($names === ['range1', 'range2', 'root'], 'all spans serialized once');

echo "OK\n";
?>
--EXPECT--
OK
