--TEST--
A delayed OOM raised while loading a deferred integration terminates the request
--SKIPIF--
<?php if (getenv('USE_ZEND_ALLOC') === '0') die('skip Zend memory manager required'); ?>
<?php if (ini_get('memory_hard_limit_percent') === false) die('skip PHP without delayed OOM (8.2.35, 8.3.36, 8.4.27, 8.5.12+)'); ?>
--ENV--
_DD_LOAD_TEST_INTEGRATIONS=1
--INI--
memory_limit=32M
memory_hard_limit_percent=1000
--FILE--
<?php

namespace DDTrace\Test
{
    class TestSandboxedIntegration implements \DDTrace\Integration
    {
        static function init(): int
        {
            echo "not reached\n";
            return self::LOADED;
        }
    }
}

namespace
{
    register_shutdown_function(function () {
        echo "shutdown: ", error_get_last()['message'] ?? 'no error', "\n";
    });

    class Test
    {
        public static function public_static_method($s)
        {
            echo "not reached\n";
        }
    }

    $a = str_repeat('x', 20 << 20);
    Test::public_static_method($a . $a); // the concat exceeds memory_limit, the next safe point is the deferred integration load
    echo "not reached\n";
    var_dump(array_map(fn ($i) => $i, [1]));
}
?>
--EXPECTF--
Fatal error: Allowed memory size of 33554432 bytes exhausted %S(tried to allocate %d bytes) in %s on line %d
shutdown: Allowed memory size of 33554432 bytes exhausted %S(tried to allocate %d bytes)
