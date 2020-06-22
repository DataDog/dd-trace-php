--TEST--
Ensure that if a user adds an internal function we already trace to traced internal functions list that it doesn't misbehave
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=header
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: requires DDTrace\\trace_function'); ?>
--FILE--
<?php

DDTrace\trace_function('header', function () {
    echo "Traced header.\n";
});

header("x-datatdog-test-header: foo");

?>
--EXPECT--
Traced header.

