--TEST--
DDTrace_trace_function is passed wrong parameters
--FILE--
<?php

try {
    \DDTrace\trace_function();
} catch (ArgumentCountError $e) {
    echo "OK1\n";
}

try {
    \DDTrace\trace_function("foo", "method");
} catch (TypeError $e) {
    echo "OK2\n";
}

try {
    \DDTrace\trace_function("foo", "method", function () { });
} catch (ArgumentCountError $e) {
    echo "OK3\n";
}

try {
    \DDTrace\trace_function("foo", function () { }, function () { });
} catch (ArgumentCountError $e) {
    echo "OK4\n";
}

?>
--EXPECT--
OK1
OK2
OK3
OK4
