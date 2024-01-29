--TEST--
Die()'ing in the sandbox is properly caught
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: UnwindExit does not exist on PHP 7'); ?>
--INI--
datadog.trace.debug=1
--FILE--
<?php

function x() {
    die();
}
\DDTrace\trace_function("x", function($s) { die(); });
x();

?>
--EXPECTF--
[ddtrace] [warning] UnwindExit thrown in ddtrace's closure defined at %s:%d for x(): <exit>
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
