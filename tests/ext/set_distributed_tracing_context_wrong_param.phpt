--TEST--
Wrong tags parameter passed to set_distributed_tracing_context
--FILE--
<?php

\DDTrace\set_distributed_tracing_context("1", "1", "1", 0);

?>
--EXPECTF--
Fatal error: Uncaught TypeError: DDTrace\set_distributed_tracing_context expects parameter 4 to be of type array, string or null, int%s given in %s:%d
Stack trace:
#0 %s(%d): DDTrace\set_distributed_tracing_context('1', '1', '1', 0)
#1 {main}
  thrown in %s on line %d
