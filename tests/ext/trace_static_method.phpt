--TEST--
Public static method tracing.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php

class Test
{
    public static function public_static_method()
    {
        return "PUBLIC STATIC METHOD" . PHP_EOL;
    }

}

dd_trace("Test", "public_static_method", function(){
    return "test_access hook start" . PHP_EOL . Test::public_static_method() . "test_access hook end" . PHP_EOL;
});

echo Test::public_static_method();
?>

--EXPECT--
test_access hook start
PUBLIC STATIC METHOD
test_access hook end
