--TEST--
Starting a trace in a prehook and closing it in the posthook while an active one exists does not confuse the limiter
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_TRACE_LOG_LEVEL=info,startup=off
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
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
string(34) "newly active :13874630024467741450"
string(28) "initial :1735254072534978428"
string(29) "started :10598951352238613536"
bar
baz
baz() called
bar() called
string(29) "current :10598951352238613536"
string(29) "closing :10598951352238613536"
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
string(33) "newly active :1735254072534978428"
string(28) "initial :5052085463162682550"
string(28) "started :7199227068870524257"
bar
baz
baz() called
bar() called
string(28) "current :7199227068870524257"
string(28) "closing :7199227068870524257"
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
string(33) "newly active :5052085463162682550"
foo() called
[ddtrace] [info] Flushing trace of size 5 to send-queue for %s
[ddtrace] [info] No finished traces to be sent to the agent