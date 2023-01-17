--TEST--
Test creating a new span stack on top level
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

# start span stack
$new_stack = DDTrace\create_stack();
print 'That new span stack has the same parent: '; var_dump($new_stack == DDTrace\active_stack());

$stack_span = DDTrace\start_span();
print 'That top-level span is, in fact a trace root span without parent: '; var_dump($stack_span->parent == null);
print 'And it has matching a trace id: '; var_dump($stack_span->id == DDTrace\trace_id());

DDTrace\close_span();
echo 'Verify the stack_span stays if the top-level span is closed - this span stack is not tied to a trace directly: '; var_dump($new_stack == DDTrace\active_stack());
print 'There is no active span now: '; var_dump(DDTrace\active_span() == null);

# Attempt closing on an empty span stack
DDTrace\close_span();
echo 'Given no active span, the active span stays null: '; var_dump(DDTrace\active_span() == null);
echo 'This also must not affect the active span stack: '; var_dump($new_stack == DDTrace\active_stack());

DDTrace\switch_stack();
echo 'Now, we are back on the global span stack: '; var_dump(DDTrace\active_stack() == $new_stack->parent);
echo 'Impliying we also have no active span: '; var_dump(DDTrace\active_span() == null);

dd_dump_spans();

?>
--EXPECTF--
That new span stack has the same parent: bool(true)
That top-level span is, in fact a trace root span without parent: bool(true)
And it has matching a trace id: bool(true)
Verify the stack_span stays if the top-level span is closed - this span stack is not tied to a trace directly: bool(true)
There is no active span now: bool(true)
There is no user-span on the top of the stack. Cannot close.
Given no active span, the active span stays null: bool(true)
This also must not affect the active span stack: bool(true)
Now, we are back on the global span stack: bool(true)
Impliying we also have no active span: bool(true)
spans(\DDTrace\SpanData) (1) {
  start_top_level_span_stack.php (start_top_level_span_stack.php, start_top_level_span_stack.php, cli)
    _dd.p.dm => -1
}
