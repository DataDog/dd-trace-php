--TEST--
A line hook inside a loop body keeps firing under the tracing JIT, cold and warm
--DESCRIPTION--
Blacklisting only the first non-RECV opline stops the JIT from starting a *function* trace, but tracing JIT also starts traces at loop headers, so a hook inside a loop body was silently swallowed once the loop got hot.
Two cases: arming before the function has ever run, and arming after a sibling function has already warmed the JIT up.
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
if (!ini_get('opcache.jit_buffer_size') && !function_exists('opcache_get_status')) die('skip: JIT unavailable');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.file_update_protection=0
opcache.jit_buffer_size=64M
opcache.jit=tracing
opcache.jit_hot_func=1
opcache.jit_hot_loop=1
--FILE--
<?php

function loop_target($n) {
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $s += $i;                           // 6  hooked
    }
    return $s;
}

function warmer($n) {
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $s += $i * 2;
    }
    return $s;
}

// Cold: nothing has run yet.
$hits = 0;
$id = DDTrace\install_line_hook(__FILE__, 6, function () use (&$hits) { ++$hits; });
var_dump($id < 0);
for ($k = 0; $k < 100; ++$k) {
    loop_target(40);
}
var_dump($hits);            // 100 * 40
DDTrace\remove_hook($id);

// Warm: another function has already driven the tracing JIT hard.
for ($k = 0; $k < 100; ++$k) {
    warmer(40);
}
$hits2 = 0;
$id2 = DDTrace\install_line_hook(__FILE__, 6, function () use (&$hits2) { ++$hits2; });
var_dump($id2 < 0);
for ($k = 0; $k < 100; ++$k) {
    loop_target(40);
}
var_dump($hits2);
DDTrace\remove_hook($id2);

echo "Done.\n";
?>
--EXPECT--
bool(true)
int(4000)
bool(true)
int(4000)
Done.
