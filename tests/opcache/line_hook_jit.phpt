--TEST--
DDTrace\install_line_hook() keeps firing once the tracing JIT would otherwise have taken the function over
--DESCRIPTION--
The op_array is persisted into opcache shared memory, so arming writes opline->handler in SHM -- and under opcache.protect_memory those pages are PROT_READ, so the write has to reopen them first.
Arming also has to take the function out of the JIT's hands: JIT-compiled code calls the handler baked in at compile time and never consults opline->handler, so without the blacklist the hook would silently stop firing as soon as the function got hot.

A function that is *already* JIT-compiled when the hook is installed keeps running its compiled trace, because nothing can discard code that already exists.
That case is not silent: see line_hook_jit_already_compiled.phpt.
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.protect_memory=1
opcache.jit_buffer_size=64M
opcache.jit=tracing
opcache.jit_hot_func=1
opcache.jit_hot_loop=1
--FILE--
<?php

function target($n) {
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $s += $i;
    }
    return $s;
}

$hits = 0;
$id = DDTrace\install_line_hook(__FILE__, 8, function () use (&$hits) { ++$hits; });
var_dump($id < 0);

// Well past opcache.jit_hot_func=1: without the blacklist the JIT would own this op_array long before the end.
for ($i = 0; $i < 200; ++$i) {
    target(8);
}
var_dump($hits);

DDTrace\remove_hook($id);
for ($i = 0; $i < 50; ++$i) {
    target(8);
}
var_dump($hits);
var_dump(target(4));

echo "Done.\n";
?>
--EXPECT--
bool(true)
int(200)
int(200)
int(6)
Done.
