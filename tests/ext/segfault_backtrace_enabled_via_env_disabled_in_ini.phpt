--TEST--
Dump backtrace when segmentation fault signal is raised and config enables it
--INI--
ddtrace.log_backtrace=0
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
.*ddtrace\.so.*ddtrace_backtrace_handler.*
[\S\W\D\R]*
