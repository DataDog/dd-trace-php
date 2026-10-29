--TEST--
A line hook survives opcache.jit's compile-on-first-execution trigger, for every parameter shape
--DESCRIPTION--
The third digit of opcache.jit selects the trigger, and only the tracing one was handled: 1215 installs its trigger *as* an opline handler, so the function was compiled on its first call and never consulted the instrumented instruction again.
Putting the canonical VM handler back disables that trigger, and the canonical handler is recomputable -- no private opcache structure is involved.

*Which* opline carries it is conditional in two ways, so all three parameter shapes are covered here: opcache skips receive instructions only for a function with no type hints, and even then it skips RECV and RECV_INIT but never RECV_VARIADIC.
Getting either rule wrong rewrites a body instruction and clears the flag while leaving the real trigger armed, which loses every callback in the affected function.
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
if (ini_get('opcache.jit_buffer_size') === false) die('skip: no JIT in this opcache build');
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
opcache.jit=1215
--FILE--
<?php

function untyped($n) {
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $s += $i;                           // 6
    }
    return $s;
}

function typed(int $n) {
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $s += $i;                           // 14
    }
    return $s;
}

function variadic(...$args) {
    $n = $args[0];
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $s += $i;                           // 23
    }
    return $s;
}

foreach (['untyped' => 6, 'typed' => 14, 'variadic' => 23] as $fn => $line) {
    $hits = 0;
    $id = DDTrace\install_line_hook(__FILE__, $line, function () use (&$hits) { ++$hits; });
    for ($k = 0; $k < 100; ++$k) {
        $fn(40);
    }
    DDTrace\remove_hook($id);
    printf("%-9s id=%s hits=%d\n", $fn, $id < 0 ? 'ok' : 'BAD', $hits);
}

echo "Done.\n";
?>
--EXPECT--
untyped   id=ok hits=4000
typed     id=ok hits=4000
variadic  id=ok hits=4000
Done.
