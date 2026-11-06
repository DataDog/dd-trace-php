--TEST--
Arming a line hook works when opcache.protect_memory makes the segment read-only
--SKIPIF--
<?php if (!extension_loaded('Zend OPcache')) die('skip: opcache is required'); ?>
--INI--
opcache.enable_cli=1
opcache.protect_memory=1
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// opline->handler lives in a segment that is PROT_READ in every process under this directive, so arming has to reopen it first.
// Locating the segments needs opcache's smm_shared_globals, which is only exported from PHP 8.4 -- before that the store has to be made writable some other way, or it faults.
$target = __DIR__ . '/line_hook_target.php';
require $target;
var_dump(opcache_is_script_cached($target));

$hits = 0;
$id = DDTrace\install_line_hook($target, 4, function () use (&$hits) { $hits++; });
line_hook_target();
DDTrace\remove_hook($id);
line_hook_target();
var_dump($hits);

echo "Done.\n";
?>
--EXPECT--
bool(true)
int(1)
Done.
