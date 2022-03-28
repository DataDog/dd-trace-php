--TEST--
Functions that return generators with 'yield from' are instrumented
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Generators are only fully supported on PHP 7+'); ?>
<?php if (false) die('skip: Observed generators with "yield from" are broken ATM on PHP 8+'); ?>
--FILE--
<?php
use DDTrace\SpanData;

function getResults() {
    yield from [1337, 42, 0];
}

function doSomething() {
    $generator = getResults();
    foreach ($generator as $value) {
        echo $value . PHP_EOL;
    }

    return 'Done';
}

DDTrace\trace_function('getResults', function(SpanData $s, $a, $retval) {
    $s->name = 'getResults';
    $s->resource = $retval[0];
});

DDTrace\trace_function('doSomething', function(SpanData $s, $a, $retval) {
    $s->name = 'doSomething';
    $s->resource = $retval;
});

echo doSomething() . PHP_EOL;

array_map(function($span) {
    echo $span['name'];
    echo isset($span['resource']) ? ', ' . $span['resource'] : '';
    echo PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
1337
42
0
Done
doSomething, Done
getResults, 1337
getResults, 42
getResults, 0
