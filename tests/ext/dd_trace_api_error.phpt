--TEST--
dd_trace() declarative API error cases
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_WARN_LEGACY_DD_TRACE=0
--FILE--
<?php
# Functions
var_dump(dd_trace('foo', 'bar'));
var_dump(dd_trace('foo', ['bar']));
var_dump(dd_trace('foo', [
    'bar' => 'baz',
]));
var_dump(dd_trace('foo', [
    'instrument_when_limited' => 'foo',
]));
var_dump(dd_trace('foo', [
    'innerhook' => 'foo',
]));
var_dump(dd_trace('foo', [
    'innerhook' => new stdClass(),
]));
var_dump(dd_trace('foo', [
    'posthook' => function () {},
]));
var_dump(dd_trace('foo', []));

# Methods
echo PHP_EOL;
var_dump(dd_trace('foo', 'foo', 'bar'));
var_dump(dd_trace('foo', 'foo', ['bar']));
var_dump(dd_trace('foo', 'foo', [
    'bar' => 'baz',
]));
var_dump(dd_trace('foo', 'foo', [
    'instrument_when_limited' => 'foo',
]));
var_dump(dd_trace('foo', 'foo', [
    'innerhook' => 'foo',
]));
var_dump(dd_trace('foo', 'foo', [
    'innerhook' => new stdClass(),
]));
var_dump(dd_trace('foo', 'foo', [
    'posthook' => function () {},
]));
var_dump(dd_trace('foo', 'foo', []));
?>
--EXPECT--
Unexpected parameter combination, expected (class, function, closure | config_array) or (function, closure | config_array)
bool(false)
Expected config_array to be an associative array
bool(false)
Unknown option 'bar' in config_array
bool(false)
Expected 'instrument_when_limited' to be an int
bool(false)
Expected 'innerhook' to be an instance of Closure
bool(false)
Expected 'innerhook' to be an instance of Closure
bool(false)
Legacy API does not support 'posthook'
bool(false)
Required key 'posthook', 'prehook' or 'innerhook' not found in config_array
bool(false)

Unexpected parameter combination, expected (class, function, closure | config_array) or (function, closure | config_array)
bool(false)
Expected config_array to be an associative array
bool(false)
Unknown option 'bar' in config_array
bool(false)
Expected 'instrument_when_limited' to be an int
bool(false)
Expected 'innerhook' to be an instance of Closure
bool(false)
Expected 'innerhook' to be an instance of Closure
bool(false)
Legacy API does not support 'posthook'
bool(false)
Required key 'posthook', 'prehook' or 'innerhook' not found in config_array
bool(false)
