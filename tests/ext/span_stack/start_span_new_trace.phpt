--TEST--
Test creating a new trace
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

# primary trace
$primary_trace = DDTrace\start_span();
$primary_active = DDTrace\start_span();

$new_root = DDTrace\start_trace_span();
echo 'New trace span is reflected in DDTrace\root_span(): '; var_dump($new_root == DDTrace\root_span());
echo 'New trace span is reflected in DDTrace\active_stack(): '; var_dump($new_root->stack == DDTrace\active_stack());
echo 'New trace span stack has no parent: '; var_dump($new_root->stack->parent == null);
echo 'New trace span has no parent: '; var_dump($new_root->parent == null);
echo 'New trace span has a trace id equal to itself: '; var_dump($new_root->id == DDTrace\trace_id());

# on new root
$active_new_root_span = DDTrace\start_span();

DDTrace\switch_stack($primary_trace);
echo 'stack update successful: '; var_dump($primary_trace->stack == DDTrace\active_stack());
echo 'Root span information is carried along on stack updates: '; var_dump($primary_trace == DDTrace\root_span());
DDTrace\start_span();
DDTrace\close_span();
echo 'Opened stacks stay active until swapped back: '; var_dump($primary_trace->stack == DDTrace\active_stack());

DDTrace\switch_stack($new_root);
echo 'Swapping a stack always goes back to the active top span: '; var_dump($active_new_root_span == DDTrace\active_span());

DDTrace\close_span();
echo 'After closing the trace root, we swap back to the previously active stack: '; var_dump($primary_trace->stack == DDTrace\active_stack());
echo 'With the trace root also accordingly updated: '; var_dump($primary_trace == DDTrace\root_span());

# close spans on the primary trace
DDTrace\close_span();
DDTrace\close_span();

dd_dump_spans();

?>
--EXPECT--