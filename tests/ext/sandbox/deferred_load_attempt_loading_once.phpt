--TEST--
deferred loading only happens once, even if dispatch is not overwritten
--ENV--
_DD_LOAD_TEST_INTEGRATIONS=1
DD_TRACE_DEBUG=1
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
            echo "autoload_attempted" . PHP_EOL;
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
    Test::public_static_method();
}
?>
--EXPECTF--
autoload_attempted
PUBLIC STATIC METHOD
PUBLIC STATIC METHOD
Flushing trace of size 1 to send-queue for %s
