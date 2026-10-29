--TEST--
Begin-only line spans close on normal exit, early return, exception, and self-removal
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

function range_exit($mode) {
    $inside = DDTrace\active_span();
    if ($mode === 'throw') throw new RuntimeException('application');
    if ($mode === 'early') return $inside;
    usleep(1);
    return $inside;
}
$root = DDTrace\start_span();
$line = (new ReflectionFunction('range_exit'))->getStartLine() + 1;
$end = (new ReflectionFunction('range_exit'))->getEndLine() + 1;
$spans = [];
$removal = null;
$hook = function (DDTrace\LineHookData $h) use (&$spans, &$removal) {
    if ($removal === 'before') DDTrace\remove_hook($h->id);
    $spans[] = $h->span();
    if ($removal === 'after') DDTrace\remove_hook($h->id);
};
$id = DDTrace\install_line_hook(__FILE__, $line, $hook, $end);
foreach (['normal', 'early', 'throw', 'normal'] as $mode) {
    try { $inside = range_exit($mode); } catch (RuntimeException $e) { verify($e->getMessage() === 'application', 'application exception preserved'); }
    verify(DDTrace\active_span() === $root, 'caller restored after ' . $mode);
    verify(end($spans)->getDuration() > 0, 'span closed after ' . $mode);
    if ($mode === 'throw') verify(end($spans)->exception === $e, 'escaping exception attached');
    else verify($inside === end($spans), 'span active inside the range');
}
DDTrace\remove_hook($id);
foreach (['before', 'after'] as $removal) {
    $id = DDTrace\install_line_hook(__FILE__, $line, $hook, $end);
    range_exit('normal');
    $count = count($spans);
    range_exit('normal');
    verify(count($spans) === $count, 'removed hook stays removed');
    verify(end($spans)->getDuration() > 0 && DDTrace\active_span() === $root, 'removed hook still closes its span');
}
verify(count($spans) === 6, 'all expected callbacks ran');
DDTrace\close_span();
verify(count(dd_trace_serialize_closed_spans()) === 7, 'no dangling span after begin-only hooks');

echo "OK\n";
?>
--EXPECT--
OK
