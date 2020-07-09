--TEST--
Run sandbox closures for open spans on exit
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

register_shutdown_function(function () {
    $spans = dd_trace_serialize_closed_spans();
    array_map(
        function($span) {
            echo @$span['name'], PHP_EOL;
        },
        $spans
    );
});

DDTrace\trace_function('outer', function (SpanData $span) {
    $span->name = 'outer';
    $span->resource = $span->name;
    $span->service = 'test';
    $span->type = 'custom';
});

DDTrace\trace_function('inner', function (SpanData $span) {
    $span->name = 'inner';
    $span->resource = $span->name;
    $span->service = 'test';
    $span->type = 'custom';
});

function inner() {}

function outer() {
    inner();
    exit();
}

outer();

?>
--EXPECT--
outer
inner
