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
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)

Unexpected parameter combination, expected (class, function, closure | config_array) or (function, closure | config_array)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
