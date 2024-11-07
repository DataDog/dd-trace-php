--TEST--
deferred loading only happens once, even if dispatch is not overwritten
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
_DD_LOAD_TEST_INTEGRATIONS=1
DD_TRACE_LOG_LEVEL=info,startup=off
--FILE--
<?php

namespace DDTrace\Test
{
    class TestSandboxedIntegration implements \DDTrace\Integration
    {
        function init(): int
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
--EXPECTF--
autoload_attempted
PUBLIC STATIC METHOD
PUBLIC STATIC METHOD
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
