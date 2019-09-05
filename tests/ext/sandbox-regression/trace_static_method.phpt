--TEST--
[Sandbox regression] Trace public static method
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php

class Test
{
    public static function public_static_method()
    {
        return "PUBLIC STATIC METHOD" . PHP_EOL;
    }

}

dd_trace_method("Test", "public_static_method", function($s, $a, $retval){
    echo "test_access hook start" . PHP_EOL . $retval . "test_access hook end" . PHP_EOL;
});

Test::public_static_method();
?>

--EXPECT--
test_access hook start
PUBLIC STATIC METHOD
test_access hook end
