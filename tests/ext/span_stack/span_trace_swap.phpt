--TEST--
Test creating swapping traces
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

$primary_trace = DDTrace\start_span();

$new_root = DDTrace\start_trace_span();

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
echo 'There exists no other active span stack either: '; var_dump(null == DDTrace\active_stack());

dd_dump_spans();

?>
--EXPECT--