--TEST--
Dump backtrace when segmentation fault signal is raised and config enables it
--SKIPIF--
<?php
if (getenv('SKIP_ASAN') || getenv('USE_ZEND_ALLOC') === '0') die("skip: intentionally causes segfaults");
if (file_exists("/etc/os-release") && preg_match("/alpine/i", file_get_contents("/etc/os-release"))) die("skip Unsupported LIBC");
?>
--ENV--
DD_LOG_BACKTRACE=1
--FILE--
<?php
posix_kill(posix_getpid(), 11); // boom

?>
--EXPECTREGEX--
Segmentation fault
Datadog PHP Trace extension \(DEBUG MODE\)
Received Signal 11
Note: Backtrace below might be incomplete and have wrong entries due to optimized runtime
Backtrace:
.*ddtrace\.so.*
.*
