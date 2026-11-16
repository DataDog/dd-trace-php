--TEST--
HOOK_INSTANCE on a cached static closure keeps it shared with opcache.protect_memory=1
--DESCRIPTION--
opcache.protect_memory makes opcache shared memory read-only. The declaration of the closure lives
there and is not written, so PHP 8.6 keeps sharing the closure and the hooks stack, without a crash.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80600) die('skip: static closure caching is PHP 8.6+');
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.protect_memory=1
opcache.file_update_protection=0
--FILE--
<?php

function cached() { return static function () { return 1; }; }

var_dump(opcache_is_script_cached(__FILE__));

for ($i = 0; $i < 2; ++$i) {
    $closure = cached();
    DDTrace\install_hook($closure, function () use ($i) { echo "hook $i\n"; }, null, DDTrace\HOOK_INSTANCE);
    echo "iteration $i\n";
    $closure();
}
var_dump(cached() === cached());

echo "Done.\n";
?>
--EXPECT--
bool(true)
iteration 0
hook 0
iteration 1
hook 0
hook 1
bool(true)
Done.
