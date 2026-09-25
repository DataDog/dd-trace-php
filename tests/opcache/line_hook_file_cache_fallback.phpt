--TEST--
Line hooks do not clamp request memory when opcache falls back to the file cache
--DESCRIPTION--
The narrower sibling of line_hook_file_cache_only.phpt.
With a pending restart, opcache's file-cache loader unserialises into the request arena even though file_cache_only is off -- and such an op_array has a null refcount, exactly like a persisted one.
Classifying it as shared and then re-protecting its page to PROT_READ faults as soon as anything else on that page is written.
Page permissions are now decided by asking whether the page is already writable, rather than inferring ownership from the refcount.
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
if (PHP_OS_FAMILY !== 'Linux') die('skip: needs /proc/self/maps to classify page permissions');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.file_cache={PWD}/line_hook_file_cache
opcache.file_update_protection=0
opcache.protect_memory=1
--FILE--
<?php

// The reset schedules a restart, which is what makes the loader choose request memory for the include below.
var_dump(opcache_reset());

$target = __DIR__ . '/line_hook_target.php';
require $target;

$hits = 0;
$id = DDTrace\install_line_hook($target, 4, function () use (&$hits) { ++$hits; });
var_dump($id < 0);
line_hook_target();
line_hook_target();
var_dump($hits);

DDTrace\remove_hook($id);
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
bool(true)
int(2)
Done.
