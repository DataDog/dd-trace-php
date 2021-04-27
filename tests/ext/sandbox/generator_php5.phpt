--TEST--
Functions that return generators are not instrumented
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: Generators were added in PHP 5.5'); ?>
<?php if (PHP_VERSION_ID >= 70000) die('skip: Test is for PHP 5 only'); ?>
--FILE--
<?php
use DDTrace\SpanData;

function getResults() {
    for ($i = 10; $i < 13; $i++) {
        yield $i;
    }
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
    $s->resource = $retval;
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
10
11
12
Done
doSomething, Done
