--TEST--
test that the spandata object is on top
--SKIPIF--
<?php if (PHP_MAJOR_VERSION < 7) die("skip: test works on PHP 7+"); ?>
--FILE--
<?php
dd_trace_function('foo', function (DDTrace\SpanData $span) {
    var_dump(dd_trace_internal_fn('spandata_is_top', $span));
});

function foo() {}

foo();

?>
--EXPECT--
bool(true)