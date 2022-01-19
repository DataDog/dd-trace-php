--TEST--
[PHP 7 generator smoke test] Functions use return with yield
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000 || PHP_VERSION_ID >= 80000) die('skip: Test is for PHP 7'); ?>
--ENV--
DD_TRACE_DEBUG=1
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
?>
--EXPECT--
Cannot instrument generators on PHP 7.x
20
21
22
1337
Done
Successfully triggered flush with trace of size 2
