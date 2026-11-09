--TEST--
Line spans detach across generator yields, resume with their range, and close on abandonment
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

function line_generator() {
    $start = DDTrace\active_span();
    yield $start;
    yield DDTrace\active_span();
    return 1;
}
$root = DDTrace\start_span();
$line = (new ReflectionFunction('line_generator'))->getStartLine() + 1;
$end = (new ReflectionFunction('line_generator'))->getEndLine();
$spans = [];
$ends = 0;
$id = DDTrace\install_line_hook(__FILE__, $line, function ($h) use (&$spans) { $spans[] = $h->span(); }, $end, function ($h) use (&$ends) {
    verify(DDTrace\active_span() === $h->span(), 'generator span active during end');
    ++$ends;
});
$g = line_generator();
$first = $g->current();
verify(count($spans) === 1 && $first === $spans[0] && DDTrace\active_span() === $root, 'first yield restores caller');
$caller = DDTrace\start_span();
$g->next();
verify($g->current() === $first && DDTrace\active_span() === $caller, 'resume preserves span and new caller context');
$g->next();
verify($g->getReturn() === 1 && DDTrace\active_span() === $caller && $first->getDuration() > 0, 'generator completion restores current caller');
DDTrace\close_span();
$abandoned = line_generator();
$abandoned->current();
verify(DDTrace\active_span() === $root, 'abandoned generator detached');
unset($abandoned);
gc_collect_cycles();
verify(count($spans) === 2 && $ends === 2 && $spans[1]->getDuration() > 0, 'abandonment closes span and delivers end');
verify(DDTrace\active_span() === $root, 'abandonment restores caller');
DDTrace\remove_hook($id);
DDTrace\close_span();
verify(count(dd_trace_serialize_closed_spans()) === 4, 'generator spans serialized once');

echo "OK\n";
?>
--EXPECT--
OK
