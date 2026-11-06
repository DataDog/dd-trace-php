--TEST--
A line hook under opcache.jit's hot-counter trigger is reported rather than silently lost
--DESCRIPTION--
Unlike the tracing and first-execution triggers, the hot-counter ones cannot be disabled from here: their original handlers live in zend_jit_op_array_hot_extension's orig_handlers[], whose layout is not stable across versions (master inserted a member ahead of it), and guessing wrong would write through the wrong offset into memory every worker shares.
So the hook is installed, fires until the function goes hot, and says so.
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
if (ini_get('opcache.jit_buffer_size') === false) die('skip: no JIT in this opcache build');
if (getenv('DD_TRACE_CLI_ENABLED') === '0') die('skip: tracer is disabled');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=warn,span=off,startup=off
DD_APPSEC_ENABLED=0
--INI--
datadog.trace.log_file=file://stdout
opcache.enable=1
opcache.enable_cli=1
opcache.file_update_protection=0
opcache.jit_buffer_size=64M
opcache.jit=1235
--FILE--
<?php

function hot_counter_target($n) {
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $s += $i;                           // 6
    }
    return $s;
}

$hits = 0;
$id = DDTrace\install_line_hook(__FILE__, 6, function () use (&$hits) { ++$hits; });
var_dump($id < 0);
for ($k = 0; $k < 100; ++$k) {
    hot_counter_target(40);
}
DDTrace\remove_hook($id);

echo "Done.\n";
?>
--EXPECTF--
[ddtrace] [warning] [%d] Line hook installed at %sline_hook_jit_hot_counter.php:6 under an opcache.jit trigger that cannot be disabled: the function is compiled once it is hot and the compiled code does not consult the instrumented instruction, so the hook stops firing. Use opcache.jit=tracing, which is the default in %s on line %d;%s
bool(true)
Done.
