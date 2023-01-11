--TEST--
Test creating a switching span stacks
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

# primary trace
$primary_trace = DDTrace\start_span();
$primary_active = DDTrace\start_span();

# We always switch to the nearest alive ancestor when we close a stack
$between_stack = DDTrace\create_stack();
$new_stack = DDTrace\create_stack();

$new_stack_span = DDTrace\start_span();

DDTrace\switch_stack($between_stack);
DDTrace\close_span();
echo 'We implicitly switch the span stacks as we close the $primary_active span: '; var_dump($primary_trace->stack == DDTrace\active_stack());
echo 'We are back on the root span now: '; var_dump($primary_trace == DDTrace\active_span());

$primary_new = DDTrace\start_span();

DDTrace\switch_stack($new_stack);
echo 'We can still swap to a span even if its parent span has been closed: '; var_dump($new_stack_span == DDTrace\active_span());

DDTrace\close_span();
echo 'Closing that span then moves us one ancestor higher: '; var_dump($primary_new == DDTrace\active_span());
echo 'The active stack however persists: '; var_dump($new_stack == DDTrace\active_stack());
echo 'The active stack shows its direct parent as active: '; var_dump($primary_active == $new_stack->active);

# Closes the started span on the primary trace
DDTrace\close_span();
echo 'Closing that span then moves us one ancestor higher: '; var_dump($primary_trace == DDTrace\active_span());
echo 'Finally, given that we closed a span from another stack we switch the active stack as well: '; var_dump($primary_trace->stack == DDTrace\active_stack());

DDTrace\switch_stack($new_stack);
echo 'We can at any time switch back to a specific stack, even if there is no active span on that stack: '; var_dump($new_stack == DDTrace\active_stack());
echo 'The active span is then the highest active one in the hierarchy: '; var_dump($primary_trace == DDTrace\active_span());

DDTrace\switch_stack();
echo 'Switching to the parent of the active stack: '; var_dump($between_stack == DDTrace\active_stack());

$new_root = DDTrace\start_trace_span();

# Closes the primary trace itself
DDTrace\switch_stack();
DDTrace\close_span();

echo 'We closed the primary trace. No other span is active right now: '; var_dump(null == DDTrace\active_span());

DDTrace\switch_stack($new_root);
echo 'But we can still swap to stacks started before that: '; var_dump($new_root == DDTrace\active_span());

DDTrace\close_span();
echo 'We closed the active stack after all other stacks were closed. No other span is active right now: '; var_dump(null == DDTrace\active_span());

dd_dump_spans();

?>
--EXPECTF--
We implicitly switch the span stacks as we close the $primary_active span: bool(true)
We are back on the root span now: bool(true)
We can still swap to a span even if its parent span has been closed: bool(true)
Closing that span then moves us one ancestor higher: bool(true)
The active stack however persists: bool(true)
The active stack shows its direct parent as active: bool(true)
Closing that span then moves us one ancestor higher: bool(true)
Finally, given that we closed a span from another stack we switch the active stack as well: bool(true)
We can at any time switch back to a specific stack, even if there is no active span on that stack: bool(true)
The active span is then the highest active one in the hierarchy: bool(true)
Switching to the parent of the active stack: bool(true)
We closed the primary trace. No other span is active right now: bool(true)
But we can still swap to stacks started before that: bool(true)
We closed the active stack after all other stacks were closed. No other span is active right now: bool(true)
spans(\DDTrace\SpanData) (2) {
  span_stack_swap.php (span_stack_swap.php, span_stack_swap.php, cli)
    _dd.p.dm => -1
     (span_stack_swap.php, cli)
       (span_stack_swap.php, cli)
     (span_stack_swap.php, cli)
  span_stack_swap.php (span_stack_swap.php, span_stack_swap.php, cli)
    _dd.p.dm => -1
}
