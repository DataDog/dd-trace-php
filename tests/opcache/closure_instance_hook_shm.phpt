--TEST--
HOOK_INSTANCE on a cached static closure declared in opcache shared memory
--DESCRIPTION--
On PHP 8.6, the first instance hook on a cached static closure disables the caching at its declaration.
With opcache, that declaration lives in shared memory, which is writable without protect_memory.
--SKIPIF--
<?php if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.protect_memory=0
opcache.file_update_protection=0
--FILE--
<?php

function cached() { return static function () { return 1; }; }

var_dump(opcache_is_script_cached(__FILE__));

for ($i = 0; $i < 3; ++$i) {
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
bool(false)
Done.
