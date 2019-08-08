--TEST--
Dump backtrace when segmentation fault signal is raised and config enables it
--SKIPIF--
<?php
preg_match("/alpine/i", file_get_contents("/etc/os-release")) and die("skip Unsupported LIBC");
if (getenv('SKIP_ASAN')) die("skip: intentionally causes segfaults");
?>
--ENV--
DD_LOG_BACKTRACE=1
--FILE--
<?php
posix_kill(posix_getpid(), 11); // boom

?>
--EXPECTREGEX--
Datadog PHP Trace extension \(DEBUG MODE\)
Received Signal 11
Note: Backtrace below might be incomplete and have wrong entries due to optimized runtime
Backtrace:
.*ddtrace\.so.*
.*
