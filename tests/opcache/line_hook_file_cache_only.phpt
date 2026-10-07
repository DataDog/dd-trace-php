--TEST--
Line hooks work when opcache runs from its file cache, where there is no shared segment at all
--DESCRIPTION--
With file_cache_only there is no shared memory: scripts are unserialized into per-request arena memory, and smm_shared_globals is never populated.
Classifying such an op_array as shared makes the arm reach for a shared reference it cannot have, and -- worse -- makes the protect/unprotect path mprotect a page of the request arena back to PROT_READ, which faults as soon as anything else on that page is written.
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.file_cache={PWD}/line_hook_file_cache
opcache.file_cache_only=1
opcache.file_update_protection=0
opcache.protect_memory=1
--FILE--
<?php

$target = __DIR__ . '/line_hook_target.php';
require $target;

$hits = 0;
$id = DDTrace\install_line_hook($target, 4, function () use (&$hits) { ++$hits; });
var_dump($id < 0);
line_hook_target();
line_hook_target();
var_dump($hits);

DDTrace\remove_hook($id);
line_hook_target();
var_dump($hits);

echo "Done.\n";
?>
--CLEAN--
<?php
$dir = __DIR__ . '/line_hook_file_cache';
if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                                        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->getFilename() === '.gitkeep') { continue; }
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
}
?>
--EXPECT--
bool(true)
int(2)
int(2)
Done.
