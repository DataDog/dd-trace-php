--TEST--
[profiling] sampling shouldn't crash on `ZEND_BIND_STATIC` opcode
--DESCRIPTION--
Beginning with PHP 7.4 and still on master at the time of this writing (post
PHP 8.3), the ZEND_BIND_STATIC opcode doesn't save its opline. If it occurs on
a new frame before some other opcode has saved the opline, and then the
allocation profiler (or any other thing which examines oplines) triggers, then
the invalid opline will be accessed, possibly leading to a crash.

There was a partial fix in PHP 8.0.12 with this commit:
https://github.com/php/php-src/commit/ec54ffad1e3b15fedfd07f7d29d97ec3e8d1c45a

But there's an `zend_array_dup` operation which can occur before this the call
to `SAVE_OPLINE()`, so if the allocation profiler triggers there, it will
access the invalid opline (non-null and invalid).
--ENV--
DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE=1
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70400)
    echo "skip: test requires typed properties", PHP_EOL;
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling", PHP_EOL;
?>
--FILE--
<?php
function &ref() {
    static $a = 5;
    return $a;
}

class Foo {
    public static int $i;
    public static string $s = "x";
}

var_dump(Foo::$i = "1");
var_dump(Foo::$s, Foo::$i);

// Crash was here.
var_dump(ref());

?>
--EXPECTF--
int(1)
string(1) "x"
int(1)
int(5)

