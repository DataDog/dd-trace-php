--TEST--
Test hooking fake closures of internal functions via install_hook()
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support XFAIL");
?>
--XFAIL--
Not implemented yet
--FILE--
<?php

DDTrace\install_hook((new ReflectionFunction("time"))->getClosure(), function() {
    echo "invoked\n";
});

((new ReflectionFunction("time"))->getClosure())();

?>
--EXPECT--
invoked