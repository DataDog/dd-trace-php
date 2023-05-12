--TEST--
Starting a trace in a prehook and closing it in the posthook while an active one exists does not confuse the limiter
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_TRACE_DEBUG=1
--FILE--
<?php

function foo()
{
    echo "foo\n";
    bar();
    bar();
    bar();
}

function bar()
{
    echo "bar\n";
    baz();
}

function baz()
{
    echo "baz\n";
}

DDTrace\trace_function(
    'bar',
    [
        'prehook' => function() use (&$newTrace) {
            var_dump('initial :' . dd_trace_peek_span_id());
            $newTrace = \DDTrace\start_trace_span();
            var_dump('started :' . dd_trace_peek_span_id());
        },
        'posthook' => function (\DDTrace\SpanData $span) use (&$newTrace) {
            echo "bar() called\n";
            var_dump('current :' . dd_trace_peek_span_id());
            $activeSpan = \DDTrace\active_span();
            if ($activeSpan == $newTrace) {
                var_dump('closing :' . dd_trace_peek_span_id());
                \DDTrace\close_span();
            }
            var_dump('newly active :' . dd_trace_peek_span_id());
        }
    ]
);

DDTrace\trace_function('foo', function () {
    echo "foo() called\n";
});

DDTrace\trace_function('baz', function () {
    echo "baz() called\n";
});

foo();

?>
--EXPECTF--
foo
string(29) "initial :13874630024467741450"
string(28) "started :2513787319205155662"
bar
baz
baz() called
bar() called
string(28) "current :2513787319205155662"
string(28) "closing :2513787319205155662"
Flushing trace of size 2 to send-queue for %s
string(34) "newly active :13874630024467741450"
string(29) "initial :10598951352238613536"
string(28) "started :6878563960102566144"
bar
baz
baz() called
bar() called
string(28) "current :6878563960102566144"
string(28) "closing :6878563960102566144"
Flushing trace of size 2 to send-queue for %s
string(34) "newly active :10598951352238613536"
string(27) "initial :228421809995595595"
string(28) "started :9660662969780974662"
bar
baz
baz() called
bar() called
string(28) "current :9660662969780974662"
string(28) "closing :9660662969780974662"
Flushing trace of size 2 to send-queue for %s
string(32) "newly active :228421809995595595"
foo() called
Flushing trace of size 5 to send-queue for %s
No finished traces to be sent to the agent
