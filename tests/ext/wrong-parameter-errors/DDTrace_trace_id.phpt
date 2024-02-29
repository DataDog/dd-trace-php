--TEST--
DDTrace_trace_id is passed wrong parameters
--FILE--
<?php

try {
    \DDTrace\trace_id("foo");
} catch (ArgumentCountError $e) {
    echo "OK";
}

?>
--EXPECT--
OK
