--TEST--
DDTrace\trace_function() and DDTrace\trace_method() declarative API error cases
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php
# Functions
var_dump(DDTrace\trace_function('foo', 'bar'));
var_dump(DDTrace\trace_function('foo', ['bar']));
var_dump(DDTrace\trace_function('foo', [
    'bar' => 'baz',
]));
var_dump(DDTrace\trace_function('foo', [
    'instrument_when_limited' => 'foo',
]));
var_dump(DDTrace\trace_function('foo', [
    'posthook' => 'foo',
]));
var_dump(DDTrace\trace_function('foo', [
    'posthook' => new stdClass(),
]));
var_dump(DDTrace\trace_function('foo', []));

# Methods
echo PHP_EOL;
var_dump(DDTrace\trace_method('foo', 'foo', 'bar'));
var_dump(DDTrace\trace_method('foo', 'foo', ['bar']));
var_dump(DDTrace\trace_method('foo', 'foo', [
    'bar' => 'baz',
]));
var_dump(DDTrace\trace_method('foo', 'foo', [
    'instrument_when_limited' => 'foo',
]));
var_dump(DDTrace\trace_method('foo', 'foo', [
    'posthook' => 'foo',
]));
var_dump(DDTrace\trace_method('foo', 'foo', [
    'posthook' => new stdClass(),
]));
var_dump(DDTrace\trace_method('foo', 'foo', []));
?>
--EXPECT--
Unexpected parameters, expected (function_name, tracing_closure | config_array)
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
Required key 'posthook' or 'prehook' not found in config_array
bool(false)

Unexpected parameters, expected (class_name, method_name, tracing_closure | config_array)
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
Required key 'posthook' or 'prehook' not found in config_array
bool(false)
Successfully triggered flush with trace of size 1
