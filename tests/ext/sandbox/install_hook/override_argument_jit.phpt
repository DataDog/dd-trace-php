--TEST--
overrideArguments() works with JIT (Issue #2174)
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: JIT is only on PHP 8'); ?>
--INI--
opcache.enable=1
opcache.enable_cli = 1
opcache.jit_buffer_size=512M
opcache.jit=1255
zend_extension=opcache.so
--FILE--
<?php

DDTrace\install_hook('BaseClass::speak',
    function (\DDTrace\HookData $hook) {
        echo "hooked in BaseClass.\n";
        $hook->args[0] = 'goodbye';
        $hook->args[1] = 'overrideDefault';
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
        echo "BaseClass::speak: $message\n";
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
BaseClass::speak: goodbye
hooked in ChildClass.
hooked in BaseClass.
BaseClass::speak: goodbye
hooked in ChildClass.
hooked in BaseClass.
BaseClass::speak: goodbye
