--TEST--
overrideArguments() works with JIT (Issue #2174)
--SKIPIF--
<?php if (!file_exists(ini_get("extension_dir") . "/opcache.so")) die('skip: opcache.so does not exist in extension_dir'); ?>
<?php if (PHP_VERSION_ID < 80000) die('skip: JIT is only on PHP 8'); ?>
<?php if (PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100 && getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip: On php 8.0 we use heuristics to match the pointer. Valgrind does not have a pointer layout matching our assumptions and will gracefully fail the test.'); ?>
--INI--
opcache.enable=1
opcache.enable_cli = 1
opcache.jit_buffer_size=128M
opcache.jit=1255
zend_extension=opcache.so
--FILE--
<?php

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
hooked in BaseClass.
BaseClass::speak: goodbye, 123
hooked in ChildClass.
hooked in BaseClass.
BaseClass::speak: goodbye, 123
hooked in ChildClass.
hooked in BaseClass.
BaseClass::speak: goodbye, 123
