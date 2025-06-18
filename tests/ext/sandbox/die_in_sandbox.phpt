--TEST--
Die()'ing in the sandbox is properly caught
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: UnwindExit does not exist on PHP 7'); ?>
--INI--
datadog.trace.debug=1
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php

function x() {
    die();
}
\DDTrace\trace_function("x", function($s) { die(); });
x();

?>
--EXPECTF--
[ddtrace] [warning] UnwindExit thrown in ddtrace's closure defined at %s:%d for x(): <exit> in Unknown on line 0
[ddtrace] [span] Encoding span: Span { service: die_in_sandbox.php, name: die_in_sandbox.php, resource: die_in_sandbox.php, type: cli, trace_id: %d, span_id: %d, parent_id: %d, start: %d, duration: %d, error: %d, meta: %s, metrics: %s, meta_struct: %s, span_links: %s, span_events: %s }
[ddtrace] [span] Encoding span: Span { service: die_in_sandbox.php, name: x, resource: x, type: cli, trace_id: %d, span_id: %d, parent_id: %d, start: %d, duration: %d, error: %d, meta: %s, metrics: %s, meta_struct: %s, span_links: %s, span_events: %s }
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s

