--TEST--
Test swapping span stacks due to auto-close of spans by functions
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
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
    echo 'And the span started within the inner function was automatically closed, given that it is on the same span stack: '; var_dump($inner_span->getDuration() === 0);
    return DDTrace\start_span();
}

function inner() {
    return DDTrace\start_span();
}

# primary trace
$primary_trace = DDTrace\start_span();

trace_function('outer', function() {});
trace_function('creates_span_stack', function() {});
trace_function('inner', function() {});

DDTrace\close_span();
echo 'We closed the active stack after all other stacks were closed. No other span is active right now: '; var_dump(null == DDTrace\active_span());

dd_dump_spans();

?>
--EXPECT--