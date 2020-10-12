--TEST--
[Sandbox regression] Trace public static method
--FILE--
<?php

class Test
{
    public static function public_static_method()
    {
        return "PUBLIC STATIC METHOD" . PHP_EOL;
    }

}

DDTrace\trace_method("Test", "public_static_method", function($s, $a, $retval){
    echo "test_access hook start" . PHP_EOL . $retval . "test_access hook end" . PHP_EOL;
});

Test::public_static_method();
?>

--EXPECT--
test_access hook start
PUBLIC STATIC METHOD
test_access hook end
