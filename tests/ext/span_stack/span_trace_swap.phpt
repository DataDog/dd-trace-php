--TEST--
Test creating swapping traces
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

$primary_trace = DDTrace\start_span();

$new_root = DDTrace\start_trace_span();
$new_root->name = "other root";

DDTrace\switch_stack($primary_trace);
echo 'We are back on our primary stack: '; var_dump($primary_trace == DDTrace\active_span());

# Closes the primary trace itself
DDTrace\close_span();

echo 'We closed the primary trace. No other span is active right now: '; var_dump(null == DDTrace\active_span());
echo 'And no other stack is active either: '; var_dump(null == DDTrace\active_stack());

DDTrace\switch_stack($new_root->stack);
echo 'But we can still swap to stacks started before that: '; var_dump($new_root == DDTrace\active_span());

DDTrace\close_span();
echo 'We closed the active stack after all other stacks were closed. No other span is active right now: '; var_dump(null == DDTrace\active_span());
echo 'This automatically switches back to the parent stack: '; var_dump($primary_trace->stack == DDTrace\active_stack());

dd_dump_spans();

?>
--EXPECTF--
We are back on our primary stack: bool(true)
We closed the primary trace. No other span is active right now: bool(true)
And no other stack is active either: bool(false)
But we can still swap to stacks started before that: bool(true)
We closed the active stack after all other stacks were closed. No other span is active right now: bool(true)
This automatically switches back to the parent stack: bool(true)
spans(\DDTrace\SpanData) (2) {
  span_trace_swap.php (span_trace_swap.php, span_trace_swap.php, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
  other root (span_trace_swap.php, other root, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
}
