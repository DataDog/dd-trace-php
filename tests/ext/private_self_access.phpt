--TEST--
Private self access
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
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

dd_trace("Test", "test_access", function(){
    return "test_access hook start" . PHP_EOL . self::public_static_method() . self::private_static_method() . "test_access hook end" . PHP_EOL . $this->test_access();
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
