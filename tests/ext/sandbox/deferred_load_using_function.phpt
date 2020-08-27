--TEST--
deferred loading dispatch can be overridden
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--ENV--
_DD_LOAD_TEST_INTEGRATIONS=1
--INI--
ddtrace.request_init_hook={PWD}/deferred_loading_helper.php
--FILE--
<?php

namespace DDTrace\Test
{
    use DDTrace\Integrations\Integration;

    class TestSandboxedIntegration extends Integration
    {
        function init()
        {
            dd_trace_method("Test", "public_static_method", [
                'prehook' => function() {
                    echo "test_access hook" . PHP_EOL;
                }
            ]);
            return Integration::LOADED;
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
