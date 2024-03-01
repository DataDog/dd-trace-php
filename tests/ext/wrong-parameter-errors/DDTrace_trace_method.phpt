--TEST--
DDTrace_trace_method is passed wrong parameters
--FILE--
<?php

declare(strict_types = 1);

if (PHP_VERSION_ID < 70100) {
    try {
        \DDTrace\trace_method();
    } catch (TypeError $e) {
        echo "OK1\n";
    }

    try {
        \DDTrace\trace_method("foo");
    } catch (TypeError $e) {
        echo "OK2\n";
    }
} else {
    try {
        \DDTrace\trace_method();
    } catch (ArgumentCountError $e) {
        echo "OK1\n";
    }

    try {
        \DDTrace\trace_method("foo");
    } catch (ArgumentCountError $e) {
        echo "OK2\n";
    }
}


try {
    \DDTrace\trace_method("foo", function () { });
} catch (TypeError $e) {
    echo "OK3\n";
}

try {
    \DDTrace\trace_method("foo", "method", function () { }, "function");
} catch (TypeError $e) {
    echo "OK4\n";
}

?>
--EXPECT--
OK1
OK2
OK3
OK4
