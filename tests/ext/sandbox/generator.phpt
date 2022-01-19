--TEST--
[PHP 7 generator smoke test] Functions return generators
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000 || PHP_VERSION_ID >= 80000) die('skip: Test is for PHP 7'); ?>
--ENV--
DD_TRACE_DEBUG=1
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
?>
--EXPECT--
Cannot instrument generators on PHP 7.x
10
11
12
Done
Successfully triggered flush with trace of size 2
