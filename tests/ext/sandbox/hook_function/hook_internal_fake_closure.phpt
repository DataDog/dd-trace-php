--TEST--
Test hooking fake closures of internal functions
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support XFAIL");
?>
--XFAIL--
Not implemented yet
--FILE--
<?php

DDTrace\hook_function("time", function() {
    echo "invoked\n";
});

((new ReflectionFunction("time"))->getClosure())();

?>
--EXPECT--
invoked