--TEST--
deferred loading doesn't crash if integration loading fails
--ENV--
_DD_LOAD_TEST_INTEGRATIONS=1
--INI--
datadog.trace.log_level=warn
--FILE--
<?php

namespace DDTrace\Test
{
    class TestSandboxedIntegration implements \DDTrace\Integration
    {
        static function init(): int
        {
            \trigger_error("Fatal!", E_USER_ERROR);
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
--EXPECTF--
[ddtrace] [warning] Error raised in ddtrace's integration autoloader for ddtrace\test\testsandboxedintegration: Fatal! in %sdeferred_load_fatal.php on line %d
PUBLIC STATIC METHOD
PUBLIC STATIC METHOD
