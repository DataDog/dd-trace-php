--TEST--
deferred loading doesn't trigger nor crash if DDTrace\Integrations\load_deferred_integration is missing
--DESCRIPTION--
This issue was reported in a GitHub issue:
https://github.com/DataDog/dd-trace-php/issues/1021
--SKIPIF--
<?php if (PHP_MAJOR_VERSION < 7) die('skip: deferred loading not supported on PHP 5'); ?>
--ENV--
_DD_LOAD_TEST_INTEGRATIONS=1
--INI--
ddtrace.request_init_hook=
--FILE--
<?php

namespace DDTrace\Test
{
    class TestSandboxedIntegration
    {
        const LOADED = 1;

        function init()
        {
            echo "autoload_attempted" . PHP_EOL;
            return self::LOADED;
        }
    }
}

namespace
{
    class Test
    {
        public static function public_static_method()
        {
            echo "PUBLIC STATIC METHOD" . PHP_EOL;
        }
    }

    Test::public_static_method();
    Test::public_static_method();
}
?>
--EXPECT--
PUBLIC STATIC METHOD
PUBLIC STATIC METHOD
