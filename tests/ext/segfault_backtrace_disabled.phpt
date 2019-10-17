--TEST--
Don't Dump backtrace when segmentation fault signal is raised and config is defalt
--SKIPIF--
<?php
if (getenv('SKIP_ASAN')) die("skip: intentionally causes segfaults");
if (file_exists("/etc/os-release") && preg_match("/alpine/i", file_get_contents("/etc/os-release"))) die("skip Unsupported LIBC");
?>
--FILE--
<?php
posix_kill(posix_getpid(), 11); // boom

// should not execute; if a sigsegv handler is used it may happen
echo "Continued after segfault?!\n";

?>
--EXPECTREGEX--
(Segmentation fault.*)|(Termsig=11)
