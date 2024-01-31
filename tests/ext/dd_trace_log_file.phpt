--TEST--
Using DD_TRACE_LOG_FILE
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
?>
--INI--
datadog.trace.log_file={PWD}/dd_trace_log_file.log
datadog.trace.log_level="Off,span=Trace"
datadog.trace.generate_root_span=0
--FILE--
<?php

readfile(__DIR__ . "/dd_trace_log_file.log");

?>
--EXPECTF--
[%s] [ddtrace] [span] Creating new root SpanStack: %d, parent_stack: 0
--CLEAN--
<?php @unlink(__DIR__ . "/dd_trace_log_file.log"); ?>
