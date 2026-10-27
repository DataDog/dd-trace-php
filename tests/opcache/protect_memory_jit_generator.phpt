--TEST--
[Regression] JIT-blacklisting a hooked generator survives opcache.protect_memory=1
--DESCRIPTION--
Resolving a hook on a generator makes zai_hook_resolve_hooks_entry() call
zai_jit_blacklist_function_inlining(), which writes the tracing JIT's trace_flags and the
opline handler straight into opcache SHM. With opcache.protect_memory=1 those pages are
PROT_READ, so the writes must be preceded by a page-aligned mprotect() -- an unaligned mask
makes mprotect() fail with EINVAL and the following store segfaults. Generators are the only
path that reaches that mprotect, which is why protect_memory.phpt never caught it.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: JIT is only available on PHP 8+');
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

function counter(int $n) {
    for ($i = 0; $i < $n; $i++) {
        yield $i;
    }
}

$resumptions = 0;
DDTrace\install_hook('counter', function () use (&$resumptions) {
    ++$resumptions;
});

// Run it hot so the tracing JIT actually attaches trace counters to the blacklisted op_array.
$sum = 0;
for ($round = 0; $round < 64; ++$round) {
    foreach (counter(8) as $value) {
        $sum += $value;
    }
}

var_dump($sum);
var_dump($resumptions > 0);

echo "Done.\n";
?>
--EXPECT--
int(1792)
bool(true)
Done.
