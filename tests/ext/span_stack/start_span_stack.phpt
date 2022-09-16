--TEST--
Test creating a new span stack
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

# primary trace
$primary_trace = DDTrace\start_span();
$primary_active = DDTrace\start_span();

# start independent span stack
$new_stack = DDTrace\create_stack();
print 'That new span stack has the same parent: '; var_dump($new_stack->parent == $primary_active);
$stack_span = DDTrace\start_span();
print 'A span on a span stack references its stack: '; var_dump($stack_span->stack == $new_stack);
print 'Starting a new stack span retains DDTrace\root_span(): '; var_dump($primary_trace == DDTrace\root_span());
print 'And updates DDTrace\active_stack(): '; var_dump($new_stack == DDTrace\active_stack());
print 'That new span on the span stack has the same parent: '; var_dump($stack_span->parent == $primary_active);
print 'That new span stack does not touch trace id: '; var_dump($stack_span->id != DDTrace\trace_id());

DDTrace\close_span();
echo 'Verify the stack_span stays if spans on that stack are closed: '; var_dump($new_stack == DDTrace\active_stack());

# close independent span
DDTrace\close_span();
echo 'Active stack is swapped back when a span below the current span stack is closed: '; var_dump($primary_trace->stack == DDTrace\stack_span());

# close primary span
DDTrace\close_span();

dd_dump_spans();

?>
--EXPECT--