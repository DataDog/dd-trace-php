--TEST--
DDTrace_hook_function is passed wrong parameters
--FILE--
<?php

try {
    \DDTrace\hook_function();
} catch (ArgumentCountError $e) {
    echo "OK1\n";
}

try {
    \DDTrace\hook_function("foo", "method");
} catch (TypeError $e) {
    echo "OK2\n";
}

try {
    \DDTrace\hook_function("foo", "method", function () { });
} catch (TypeError $e) {
    echo "OK3\n";
}

try {
    \DDTrace\hook_function("foo", function () { }, "string");
} catch (TypeError $e) {
    echo "OK4\n";
}

try {
    \DDTrace\hook_function("foo", function () { }, function () { }, function () { });
} catch (ArgumentCountError $e) {
    echo "OK5\n";
}

?>
--EXPECT--
OK1
OK2
OK3
OK4
OK5
