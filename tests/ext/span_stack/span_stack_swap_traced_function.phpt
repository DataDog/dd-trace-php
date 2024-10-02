--TEST--
Test swapping span stacks due to auto-close of spans by functions
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_AUTOFINISH_SPANS=1
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

function outer() {
    $active_span = DDTrace\active_span();
    $a_span_on_another_stack = creates_span_stack();
    echo 'We called a function on the current span stack: '; var_dump($active_span == DDTrace\active_span());
    echo 'And the span stack was switched back after the function on the original stack left: '; var_dump($active_span->stack == DDTrace\active_stack());
    echo 'Also, the span on the other stack was not automatically closed: '; var_dump($a_span_on_another_stack->getDuration() === 0);

    DDTrace\switch_stack($a_span_on_another_stack);
    DDTrace\close_span();
    echo 'Now, we have explicitly closed it: '; var_dump($a_span_on_another_stack->getDuration() !== 0);
}

function creates_span_stack() {
    $new_stack = DDTrace\create_stack();
    $inner_span = inner();
    echo 'The stack stays intact within the function: '; var_dump($new_stack == DDTrace\active_stack());
    echo 'And the span started within the inner function was automatically closed, given that it is on the same span stack: '; var_dump($inner_span->getDuration() !== 0);
    return DDTrace\start_span();
}

function inner() {
    return DDTrace\start_span();
}

# primary trace
$primary_trace = DDTrace\start_span();

DDTrace\trace_function('outer', function() {});
DDTrace\trace_function('creates_span_stack', function() {});
DDTrace\trace_function('inner', function() {});

outer();

DDTrace\close_span();
echo 'We closed the active stack after all other stacks were closed. No other span is active right now: '; var_dump(null == DDTrace\active_span());

dd_dump_spans();

?>
--EXPECTF--
The stack stays intact within the function: bool(true)
And the span started within the inner function was automatically closed, given that it is on the same span stack: bool(true)
We called a function on the current span stack: bool(true)
And the span stack was switched back after the function on the original stack left: bool(true)
Also, the span on the other stack was not automatically closed: bool(true)
Now, we have explicitly closed it: bool(true)
We closed the active stack after all other stacks were closed. No other span is active right now: bool(true)
spans(\DDTrace\SpanData) (1) {
  span_stack_swap_traced_function.php (span_stack_swap_traced_function.php, span_stack_swap_traced_function.php, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
    outer (span_stack_swap_traced_function.php, outer, cli)
      creates_span_stack (span_stack_swap_traced_function.php, creates_span_stack, cli)
        inner (span_stack_swap_traced_function.php, inner, cli)
           (span_stack_swap_traced_function.php, cli)
         (span_stack_swap_traced_function.php, cli)
}
