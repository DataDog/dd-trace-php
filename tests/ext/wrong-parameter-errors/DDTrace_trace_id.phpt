--TEST--
DDTrace_trace_id is passed wrong parameters
--FILE--
<?php

declare(strict_types = 1);

try {
    \DDTrace\trace_id("foo");
} catch (ArgumentCountError $e) {
    echo "OK";
}

?>
--EXPECT--
OK
