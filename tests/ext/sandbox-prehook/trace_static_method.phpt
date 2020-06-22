--TEST--
[Prehook regression] Trace public static method
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--FILE--
<?php
class Test
{
    public static function public_static_method()
    {
        echo "PUBLIC STATIC METHOD" . PHP_EOL;
    }

}

DDTrace\trace_method("Test", "public_static_method", ['prehook' => function(){
    echo "test_access hook" . PHP_EOL;
}]);

Test::public_static_method();
?>
--EXPECT--
test_access hook
PUBLIC STATIC METHOD
