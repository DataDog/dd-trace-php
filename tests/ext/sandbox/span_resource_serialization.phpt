--TEST--
Resource is replaced by name if null-ish
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--FILE--
<?php
use DDTrace\SpanData;

DDTrace\trace_function('with_nothing', function (SpanData $span) { });

DDTrace\trace_function('with_null', function (SpanData $span) {
    $span->resource = null;
});

DDTrace\trace_function('with_false', function (SpanData $span) {
    $span->resource = false;
});

DDTrace\trace_function('with_empty_string', function (SpanData $span) {
    $span->resource = "";
});

DDTrace\trace_function('with_value', function (SpanData $span) {
    $span->resource = "abc";
});

function with_nothing() {}
function with_null() {}
function with_false() {}
function with_empty_string() {}
function with_value() {}

with_nothing();
with_null();
with_false();
with_empty_string();
with_value();

foreach (dd_trace_serialize_closed_spans() as $span) {
    echo "name: {$span['name']}; resource: {$span['resource']}\n";
}

?>
--EXPECT--
name: with_value; resource: abc
name: with_empty_string; resource: with_empty_string
name: with_false; resource: with_false
name: with_null; resource: with_null
name: with_nothing; resource: with_nothing
