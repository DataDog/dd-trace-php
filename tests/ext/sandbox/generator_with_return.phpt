--TEST--
Functions that use return with yield are instrumented
--SKIPIF--
<?php if (PHP_VERSION_ID < 70100) die('skip: Generators are partially supported on PHP 7.1+'); ?>
<?php if (PHP_VERSION_ID >= 80000) die('skip: Generators are fully supported on PHP 8+'); ?>
--FILE--
<?php
use DDTrace\SpanData;

function getResultsWithReturn() {
    for ($i = 20; $i < 23; $i++) {
        yield $i;
    }
    return 1337;
}

function doSomething() {
    $generatorRet = getResultsWithReturn();
    foreach ($generatorRet as $value) {
        echo $value . PHP_EOL;
    }
    echo $generatorRet->getReturn() . PHP_EOL;

    return 'Done';
}

DDTrace\trace_function('getResultsWithReturn', function(SpanData $s, $a, $retval) {
    $s->name = 'getResultsWithReturn';
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
20
21
22
1337
Done
doSomething, Done
getResultsWithReturn, 20
