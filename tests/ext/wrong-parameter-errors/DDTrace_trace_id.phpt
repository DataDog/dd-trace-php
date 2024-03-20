--TEST--
DDTrace_trace_id is passed wrong parameters
--FILE--
<?php

declare(strict_types = 1);

if (PHP_VERSION_ID < 70100) {
    try {
        \DDTrace\trace_id("foo");
    } catch (TypeError $e) {
        echo "OK";
    }
} else {
    try {
        \DDTrace\trace_id("foo");
    } catch (ArgumentCountError $e) {
        echo "OK";
    }
}


?>
--EXPECT--
OK
