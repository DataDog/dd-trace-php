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
echo 'Given there was no stack beforehand, we reset to null: '; var_dump(DDTrace\active_stack() == null);
echo 'Impliying we also have no active span: '; var_dump(DDTrace\active_span() == null);

dd_dump_spans();

?>
--EXPECT--