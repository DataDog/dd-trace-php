--TEST--
HOOK_INSTANCE on a cached static closure declared in opcache shared memory, with the tracing JIT
--DESCRIPTION--
Same as closure_instance_hook_shm.phpt, with the declaring function and the loop compiled by the
tracing JIT, which calls the VM handler of ZEND_DECLARE_LAMBDA_FUNCTION and so reads the disabled cache.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: JIT is only available on PHP 8+');
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
if (ini_get('opcache.jit') === false) die('skip: OPcache built without JIT');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.protect_memory=0
opcache.file_update_protection=0
opcache.jit_buffer_size=64M
opcache.jit=tracing
opcache.jit_hot_func=1
opcache.jit_hot_loop=1
--FILE--
<?php

function cached() { return static function () { return 1; }; }

var_dump(opcache_get_status(false)['jit']['on']);

for ($i = 0; $i < 8; ++$i) {
    $closure = cached();
    DDTrace\install_hook($closure, function () use ($i) { echo "hook $i\n"; }, null, DDTrace\HOOK_INSTANCE);
    $closure();
    unset($closure);
}
cached()();
var_dump(cached() === cached());

echo "Done.\n";
?>
--EXPECT--
bool(true)
hook 0
hook 1
hook 2
hook 3
hook 4
hook 5
hook 6
hook 7
bool(false)
Done.
