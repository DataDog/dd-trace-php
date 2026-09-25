--TEST--
A bailout after first creating a begin-only line span does not unwind its application frame
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
function verify($ok, $label) { if (!$ok) echo "FAIL: $label\n"; }
function bailout_range() {
    return DDTrace\active_span();
}
$root = DDTrace\start_span();
$span = null;
$line = (new ReflectionFunction('bailout_range'))->getStartLine() + 1;
$id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use (&$span) {
    $span = $h->span();
    trigger_error('line span callback bailout', E_USER_ERROR);
});
$inside = bailout_range();
verify($span && $inside === $span && $span->getDuration() > 0, 'first begin-only callback bailout still closes at frame exit');
verify(DDTrace\active_span() === $root, 'bailout does not unwind the application frame');
DDTrace\remove_hook($id);
DDTrace\close_span();
verify(count(dd_trace_serialize_closed_spans()) === 2, 'span survives callback bailout');
echo "OK\n";
?>
--EXPECT--
OK
