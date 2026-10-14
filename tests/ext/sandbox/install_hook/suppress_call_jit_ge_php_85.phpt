--TEST--
suppressCall() works with JIT
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80500) die('skip: PHP 8.5+ variant (built-in OPcache) of suppress_call_jit.phpt');
if (getenv('PHP_PEAR_RUNTESTS') === '1') die('skip: pecl run-tests does not support XFAIL');
if (ZEND_VM_KIND === 'ZEND_VM_KIND_TAILCALL') die('xfail TAILCALL VM: the tracing JIT trace counter helper resumes without servicing vm_interrupt (php-src ext/opcache/jit/zend_jit_vm_helpers.c, zend_jit_trace_counter_helper)');
?>
--INI--
opcache.enable=1
opcache.enable_cli = 1
opcache.jit_buffer_size=128M
opcache.jit=1255
--FILE--
<?php

function foo() {
    var_dump('called');
}
$hook = DDTrace\install_hook('foo',
    function (\DDTrace\HookData $hook) {
        $hook->disableJitInlining();
        $hook->suppressCall();
    }
);

echo "With hook\n";
foo();

DDTrace\remove_hook($hook);

echo "Without hook\n";
foo();

--EXPECT--
With hook
Without hook
string(6) "called"
