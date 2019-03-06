--TEST--
Don't Dump backtrace when segmentation fault signal is raised and config is defalt
--SKIPIF--
<?php
preg_match("/alpine/i", file_get_contents("/etc/os-release")) and die("skip Unsupported LIBC");
?>
--FILE--
<?php
posix_kill(posix_getpid(), 11); // boom

?>
--EXPECTREGEX--
(Segmentation fault.*)|(Termsig=11)
