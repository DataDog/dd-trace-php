--TEST--
overrideArguments() works with JIT (Issue #2174)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: JIT is only on PHP 8');
if (PHP_VERSION_ID < 80100 && getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip: On php 8.0 we use heuristics to match the pointer. Valgrind does not have a pointer layout matching our assumptions and will gracefully fail the test.');
if (!function_exists('opcache_get_status')) die('skip: OPcache is required');
if (ini_get('opcache.jit') === false) die('skip: JIT support is required');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli = 1
opcache.file_update_protection=0
opcache.jit_buffer_size=128M
opcache.jit=1255
--FILE--
<?php
$status = opcache_get_status(false);
var_dump($status['jit']['on']);

global $val;
$val = 123;

DDTrace\install_hook('BaseClass::speak',
    function (\DDTrace\HookData $hook) {
        echo "hooked in BaseClass.\n";
        $hook->args[0] = 'goodbye';
        $hook->args[1] = "{$GLOBALS["val"]}"; // dynamic value
        $hook->overrideArguments($hook->args);
    }
);

DDTrace\install_hook('ChildClass::speak',
    function (\DDTrace\HookData $hook) {
        echo "hooked in ChildClass.\n";
        $hook->args[0] = 'goodbye';
        $hook->args[1] = 'overrideDefault';
        $hook->overrideArguments($hook->args);
    }
);

class BaseClass
{
    public static function speak($message, $defArg = 'w/e')
    {
        echo "BaseClass::speak: $message, $defArg\n";
    }
}

BaseClass::speak('hello');

// delay ChildClass invocation until runtime
if (true) {
    final class ChildClass extends BaseClass
    {
    }
}

for ($i = 0; $i < 2; $i++) {
    ChildClass::speak('hello');
}

--EXPECTF--
bool(true)
hooked in BaseClass.
BaseClass::speak: goodbye, 123
hooked in ChildClass.
hooked in BaseClass.
BaseClass::speak: goodbye, 123
hooked in ChildClass.
hooked in BaseClass.
BaseClass::speak: goodbye, 123
