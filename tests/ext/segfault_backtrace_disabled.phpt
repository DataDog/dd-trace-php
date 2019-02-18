--TEST--
Don't Dump backtrace when segmentation fault signal is raised and config is defalt
--FILE--
<?php
posix_kill(posix_getpid(), 11); // boom

?>
--EXPECTREGEX--
(Segmentation fault.*)|(Termsig=11)
