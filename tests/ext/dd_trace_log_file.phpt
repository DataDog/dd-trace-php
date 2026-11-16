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

// Prevent side-effects from other tests (sidecar), hence filter it
// The sidecar keeps appending other sessions' logs to this file. Read only what
// is there already (our line was logged at RINIT): reading up to EOF may never
// finish under valgrind with concurrent tests.
$log_file = __DIR__ . "/dd_trace_log_file.log";
$log = file_get_contents($log_file, false, null, 0, filesize($log_file));
preg_match("(.*\[span\].*)", $log, $m);
echo $m[0];

?>
--EXPECTF--
[%s] [ddtrace] [span] [%d] Creating new root SpanStack: %d, parent_stack: 0
--CLEAN--
<?php @unlink(__DIR__ . "/dd_trace_log_file.log"); ?>
