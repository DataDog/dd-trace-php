--TEST--
Functions that return generators with 'yield from' are instrumented
--SKIPIF--
<?php if (PHP_VERSION_ID < 70100) die('skip: Generators are partially supported on PHP 7.1+'); ?>
<?php if (PHP_VERSION_ID >= 80000) die('skip: Generators are fully supported on PHP 8+'); ?>
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
