--TEST--
[Prehook] API error cases
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
Successfully triggered flush with trace of size 1
