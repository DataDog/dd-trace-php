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
[ddtrace] [span] Encoding span %d: trace_id=%s, name='die_in_sandbox.php', service='die_in_sandbox.php', resource: 'die_in_sandbox.php', type 'cli' with tags: runtime-id='%s', _dd.p.dm='-0', _dd.p.tid='%s'; and metrics: process_id='%d', _dd.agent_psr='1', _sampling_priority_v1='1', php.compilation.total_time_ms='%f', php.memory.peak_usage_bytes='%f', php.memory.peak_real_usage_bytes='%f'
[ddtrace] [span] Encoding span %d: trace_id=%s, name='x', service='die_in_sandbox.php', resource: 'x', type 'cli' with tags: -; and metrics: -
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
