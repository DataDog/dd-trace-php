--TEST--
Line hooks resolve against preloaded functions and methods
--DESCRIPTION--
Resolution reads zai_hook's function-location map, which the declaration observers fill during compilation.
A preloaded file was compiled in another process, so nothing announces it to this request and the map never mentions it: ids came back valid, the code ran, and no callback was ever delivered.
The already-loaded symbol tables have to be consulted as well.
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
if (PHP_VERSION_ID < 70400) die('skip: opcache.preload needs PHP 7.4+');
if (PHP_OS_FAMILY === 'Windows') die('skip: opcache.preload is not supported on Windows');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.file_update_protection=0
opcache.preload={PWD}/line_hook_preload_stub.inc
--FILE--
<?php

$target = __DIR__ . '/line_hook_preload_target.inc';
var_dump(function_exists('line_hook_preloaded'));

$hits = [];
$cb = function (DDTrace\LineHookData $h) use (&$hits) { $hits[] = $h->line; };
$fn = DDTrace\install_line_hook($target, 3, $cb, 3, $cb);
$me = DDTrace\install_line_hook($target, 8, $cb, 8, $cb);
var_dump($fn < 0, $me < 0);

var_dump(line_hook_preloaded(5));
var_dump(LineHookPreloaded::method(5));
echo implode(',', $hits), "\n";

DDTrace\remove_hook($fn);
DDTrace\remove_hook($me);
echo "Done.\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
int(6)
int(6)
3,4,8,9
Done.
