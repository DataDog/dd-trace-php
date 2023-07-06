--TEST--
Finally block should only be entered once
--FILE--
<?php

function triggerError() {
    $a = null;
    $a->hello();
}

function handle() {
    try {
        triggerError();
    } catch (\Exception $e) {
        echo "This should never be printed\n";
    } finally {
        echo "This should only be printed once\n";
    }
}

\DDTrace\trace_function('handle', function (\DDTrace\SpanData $span) {
    echo "handle called\n";
});

handle();

?>
--EXPECTF--
This should only be printed once
handle called

Fatal error: Uncaught Error: Call to a member function hello() on null in %s:%d
Stack trace:
#0 %s: triggerError()
#1 %s: handle()
#2 {main}
  thrown in %s
