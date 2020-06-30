--TEST--
Builtin autoload loads only once
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--ENV--
_DD_LOAD_TEST_INTEGRATIONS=1
--FILE--
<?php
function load_test_integration(){
    echo "autoload_attempted" . PHP_EOL;
}

class Test
{
    public static function public_static_method()
    {
        echo "PUBLIC STATIC METHOD" . PHP_EOL;
    }
}

Test::public_static_method();
Test::public_static_method();
?>
--EXPECT--
autoload_attempted
PUBLIC STATIC METHOD
PUBLIC STATIC METHOD
