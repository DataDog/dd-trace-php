--TEST--
Test creating a new span stack
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

# primary trace
$primary_trace = DDTrace\start_span();
$primary_active = DDTrace\start_span();

# start independent span stack
$new_stack = DDTrace\create_stack();
print 'That new span stack has the same parent: '; var_dump($new_stack->parent == $primary_active->stack);
print 'The parent of the span stack is also its active span: '; var_dump($new_stack->active == $primary_active);
$stack_span = DDTrace\start_span();
print 'A span on a span stack references its stack: '; var_dump($stack_span->stack == $new_stack);
print 'Starting a new stack span retains DDTrace\root_span(): '; var_dump($primary_trace == DDTrace\root_span());
print 'And updates DDTrace\active_stack(): '; var_dump($new_stack == DDTrace\active_stack());
print 'Also updates the active span of the stack: '; var_dump($new_stack->active == $stack_span);
print 'Which is also reflected in the globally active span: '; var_dump(DDTrace\active_span() == $stack_span);
print 'That new span on the span stack has the same parent: '; var_dump($stack_span->parent == $primary_active);
print 'That new span stack does not touch trace id: '; var_dump($stack_span->id != DDTrace\trace_id());

DDTrace\close_span();
echo 'Verify the stack_span stays if spans on that stack are closed: '; var_dump($new_stack == DDTrace\active_stack());

# close independent span
DDTrace\close_span();
echo 'Active stack is swapped back when a span below the current span stack is closed: '; var_dump($primary_trace->stack == DDTrace\active_stack());
echo 'The stack still retains its direct parent as active: '; var_dump($new_stack->active == $primary_active);

# close primary span
DDTrace\close_span();

dd_dump_spans();

?>
--EXPECTF--
That new span stack has the same parent: bool(true)
The parent of the span stack is also its active span: bool(true)
A span on a span stack references its stack: bool(true)
Starting a new stack span retains DDTrace\root_span(): bool(true)
And updates DDTrace\active_stack(): bool(true)
Also updates the active span of the stack: bool(true)
Which is also reflected in the globally active span: bool(true)
That new span on the span stack has the same parent: bool(true)
That new span stack does not touch trace id: bool(true)
Verify the stack_span stays if spans on that stack are closed: bool(true)
Active stack is swapped back when a span below the current span stack is closed: bool(true)
The stack still retains its direct parent as active: bool(true)
spans(\DDTrace\SpanData) (1) {
  start_span_stack.php (start_span_stack.php, start_span_stack.php, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
     (start_span_stack.php, cli)
       (start_span_stack.php, cli)
}
