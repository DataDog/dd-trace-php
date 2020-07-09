--TEST--
Ensure that if a user adds an internal function we already trace to traced internal functions list that it doesn't misbehave
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=header
--FILE--
<?php

header("x-datatdog-test-header: foo");

echo "Done.\n";

?>
--EXPECT--
Done.

