--TEST--
dd_trace can skip over the call it instruments (LEGACY BEHAVIOR -- DO NOT RELY ON)
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php

dd_trace('instrumented_function', function () {
    echo "instrumented_function was instrumented\n";
});

function instrumented_function()
{
    echo "instrumented_function was not instrumented\n";
}

dd_trace('InstrumentedClass', 'instrumentedMethod', function () {
    echo "InstrumentedClass::instrumentedMethod was instrumented\n";
});

final class InstrumentedClass
{
    public static function instrumentedMethod()
    {
        echo "InstrumentedClass::instrumentedMethod was not instrumented\n";
    }
}

instrumented_function();
InstrumentedClass::instrumentedMethod();

// call again to be doubly sure
instrumented_function();
InstrumentedClass::instrumentedMethod();

?>
--EXPECT--
instrumented_function was instrumented
InstrumentedClass::instrumentedMethod was instrumented
instrumented_function was instrumented
InstrumentedClass::instrumentedMethod was instrumented
