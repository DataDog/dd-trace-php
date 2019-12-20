--TEST--
dd_trace_function() and dd_trace_method() error cases
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php
# Functions
var_dump(dd_trace_function('foo', 'bar'));
var_dump(dd_trace_function('foo', ['bar']));
var_dump(dd_trace_function('foo', [
    'bar' => 'baz',
]));
var_dump(dd_trace_function('foo', [
    'instrument_when_limited' => 'foo',
]));
var_dump(dd_trace_function('foo', [
    'posthook' => 'foo',
]));
var_dump(dd_trace_function('foo', [
    'posthook' => new stdClass(),
]));

# Methods
echo PHP_EOL;
var_dump(dd_trace_method('foo', 'foo', 'bar'));
var_dump(dd_trace_method('foo', 'foo', ['bar']));
var_dump(dd_trace_method('foo', 'foo', [
    'bar' => 'baz',
]));
var_dump(dd_trace_method('foo', 'foo', [
    'instrument_when_limited' => 'foo',
]));
var_dump(dd_trace_method('foo', 'foo', [
    'posthook' => 'foo',
]));
var_dump(dd_trace_method('foo', 'foo', [
    'posthook' => new stdClass(),
]));
?>
--EXPECT--
bool(false)
Expected config_array to be an associative array
bool(false)
Unknown option 'bar' in config_array
bool(false)
Expected 'instrument_when_limited' to be an int
bool(false)
Expected 'posthook' to be an instance of Closure
bool(false)
Expected 'posthook' to be an instance of Closure
bool(false)

bool(false)
Expected config_array to be an associative array
bool(false)
Unknown option 'bar' in config_array
bool(false)
Expected 'instrument_when_limited' to be an int
bool(false)
Expected 'posthook' to be an instance of Closure
bool(false)
Expected 'posthook' to be an instance of Closure
bool(false)
