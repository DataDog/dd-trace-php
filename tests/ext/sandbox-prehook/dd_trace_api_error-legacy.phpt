--TEST--
[Prehook] API error cases
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php
# Functions
var_dump(DDTrace\trace_function('foo', [
    'prehook' => 'foo',
]));
var_dump(DDTrace\trace_function('foo', [
    'prehook' => new stdClass(),
]));

# Methods
echo PHP_EOL;
var_dump(DDTrace\trace_method('foo', 'foo', [
    'prehook' => 'foo',
]));
var_dump(DDTrace\trace_method('foo', 'foo', [
    'prehook' => new stdClass(),
]));
?>
--EXPECT--
Expected 'prehook' to be an instance of Closure
bool(false)
Expected 'prehook' to be an instance of Closure
bool(false)

Expected 'prehook' to be an instance of Closure
bool(false)
Expected 'prehook' to be an instance of Closure
bool(false)
