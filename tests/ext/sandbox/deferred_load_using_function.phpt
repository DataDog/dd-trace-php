--TEST--
deferred loading dispatch can be overridden
--ENV--
_DD_LOAD_TEST_INTEGRATIONS=1
--FILE--
<?php

namespace DDTrace\Test
{
    class TestSandboxedIntegration implements \DDTrace\Integration
    {
        static function init(): int
        {
            dd_trace_method("Test", "public_static_method", [
                'prehook' => function() {
                    echo "test_access hook" . PHP_EOL;
                }
            ]);
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
}
?>
--EXPECT--
test_access hook
PUBLIC STATIC METHOD
