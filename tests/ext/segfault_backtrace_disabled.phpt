--TEST--
Don't Dump backtrace when segmentation fault signal is raised and config is defalt
--FILE--
<?php
posix_kill(posix_getpid(), SIGSEGV); // boom

?>
--EXPECT--
Termsig=11
