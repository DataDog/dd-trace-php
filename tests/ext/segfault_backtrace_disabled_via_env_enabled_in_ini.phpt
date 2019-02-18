--TEST--
Don't Dump backtrace when segmentation fault signal is raised and config is defalt
--INI--
ddtrace.log_backtrace=1
--ENV--
DD_LOG_BACKTRACE=0
--FILE--
<?php
posix_kill(posix_getpid(), 11); // boom

?>
--EXPECTREGEX--
(Segmentation fault.*)|(Termsig=11)
