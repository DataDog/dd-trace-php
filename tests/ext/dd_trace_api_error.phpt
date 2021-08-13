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
    'prehook' => 'foo',
]));
var_dump(dd_trace('foo', [
    'prehook' => new stdClass(),
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
    'prehook' => 'foo',
]));
var_dump(dd_trace('foo', 'foo', [
    'prehook' => new stdClass(),
]));
var_dump(dd_trace('foo', 'foo', [
    'posthook' => function () {},
]));
var_dump(dd_trace('foo', 'foo', []));

if (PHP_VERSION_ID < 70000) {
    echo "Successfully triggered flush with trace of size 1", PHP_EOL;
}

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
Successfully triggered flush with trace of size 1
