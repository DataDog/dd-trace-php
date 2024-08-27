--TEST--
Don't dump backtrace when segmentation fault signal is raised and config is default
--SKIPIF--
<?php
if (!extension_loaded('posix')) die('skip: posix extension required');
if (getenv('SKIP_ASAN') || getenv('USE_ZEND_ALLOC') === '0') die("skip: intentionally causes segfaults");
if (file_exists("/etc/os-release") && preg_match("/alpine/i", file_get_contents("/etc/os-release"))) die("skip Unsupported LIBC");
?>
--ENV--
DD_LOG_BACKTRACE=0
--FILE--
<?php
posix_kill(posix_getpid(), 11); // boom

// should not execute; if a sigsegv handler is used it may happen
echo "Continued after segfault?!\n";

?>
--EXPECTREGEX--
(Segmentation fault.*)|(Termsig=11)
