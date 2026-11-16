--TEST--
suppressCall() works with JIT
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

function foo() {
    var_dump('called');
}
$hook = DDTrace\install_hook('foo',
    function (\DDTrace\HookData $hook) {
        $hook->disableJitInlining();
        $hook->suppressCall();
    }
);

echo "With hook\n";
foo();

DDTrace\remove_hook($hook);

echo "Without hook\n";
foo();

--EXPECT--
With hook
Without hook
string(6) "called"
