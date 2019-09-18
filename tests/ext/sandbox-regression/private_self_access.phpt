--TEST--
[Sandbox regression] Tracing closure accesses private static method
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php

class Test
{
    private static function private_static_method()
    {
        return "PRIVATE STATIC METHOD" . PHP_EOL;
    }

    public static function public_static_method()
    {
        return "PUBLIC STATIC METHOD" . PHP_EOL;
    }

    public function test_access(){
        return self::public_static_method() . self::private_static_method();
    }

}

dd_trace_method("Test", "test_access", function(){
    echo "test_access hook start" . PHP_EOL . self::public_static_method() . self::private_static_method() . "test_access hook end" . PHP_EOL;
});

echo (new Test)->test_access();
?>

--EXPECT--
test_access hook start
PUBLIC STATIC METHOD
PRIVATE STATIC METHOD
test_access hook end
PUBLIC STATIC METHOD
PRIVATE STATIC METHOD
