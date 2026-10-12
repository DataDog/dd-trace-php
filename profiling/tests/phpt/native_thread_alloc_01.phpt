--TEST--
[profiling] reuse a closure after executing it on a native thread (ext-grpc compatibility)
--DESCRIPTION--
Execute a PHP closure that allocates on a native thread, join it, then call the
same closure on the main thread. PHP runtime cache slots populated by the
background thread must remain valid after its Rust TLS has been destroyed.
Disable the stack limit because the callback does not use the main thread's stack.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
  die("skip: test requires datadog-profiling");
if (PHP_ZTS)
  die("skip: test only applies to NTS builds");
if (!function_exists('Datadog\Profiling\run_on_native_thread'))
  die("skip: test function not available (requires build with CFG_TEST)");
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=yes
DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE=1
--INI--
zend.max_allowed_stack_size=-1
--FILE--
<?php
$calls = 0;
$callback = function () use (&$calls) {
    $allocation = str_repeat('a', 8 * 1024 * 1024);
    ++$calls;
    return strlen($allocation);
};

var_dump(Datadog\Profiling\run_on_native_thread($callback));
echo "Joined.\n";
var_dump($calls);
var_dump($callback());
var_dump($calls);
?>
--EXPECT--
int(8388608)
Joined.
int(1)
int(8388608)
int(2)
