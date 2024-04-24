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
    class TestSandboxedIntegration
    {
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
[ddtrace] [warning] Error loading deferred integration ddtrace\test\testsandboxedintegration: Class is not an instance of DDTrace\Integration
PUBLIC STATIC METHOD
PUBLIC STATIC METHOD
