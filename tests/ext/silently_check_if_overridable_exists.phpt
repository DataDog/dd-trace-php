--TEST--
Toggle checking if overridable method/function exists or not
--FILE--
<?php

dd_trace("ThisClassDoesntExists", "m", function(){});
dd_trace("this_function_doesnt_exist", function(){});

echo "no exception thrown";

?>
--EXPECT--
no exception thrown
