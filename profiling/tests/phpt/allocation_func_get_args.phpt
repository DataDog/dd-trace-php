--TEST--
[profiling] sampling shouldn't crash on `ZEND_FUNC_GET_ARGS` opcode
--DESCRIPTION--
Beginning with PHP 7.4, the ZEND_FUNC_GET_ARGS opcode doesn't save its opline.
If it occurs on a new frame before some other opcode has saved the opline, and
then the allocation profiler triggers (or any other thing which examines
oplines like the error message when hitting the memory limit), then the
invalid opline will be accessed, possibly leading to a crash.

Fixed in PHP 8.1.27, 8.2.14 and 8.3.1:
https://github.com/php/php-src/pull/12768

This test shouldn't crash even on affected versions, because the profiler
should mitigate the issue with a user opcode handler. However, it's difficult
to trigger at exactly the right (wrong?) time anyway, so it's unlikely to
crash anyway.
TODO: run this in some mode which will look at the opline on every allocation.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70400)
    echo "skip: test requires typed properties", PHP_EOL;
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling", PHP_EOL;
?>
--FILE--
<?php

function ref() {
    return func_get_args();
}

class Foo {
    public static int $i;
    public static string $s = "x";
}

var_dump(Foo::$i = "1");
var_dump(Foo::$s, Foo::$i);
var_dump(ref('string', 0));

echo 'Done.';
?>
--EXPECT--
int(1)
string(1) "x"
int(1)
array(2) {
  [0]=>
  string(6) "string"
  [1]=>
  int(0)
}
Done.