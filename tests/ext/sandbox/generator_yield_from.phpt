--TEST--
[PHP 7 generator smoke test] Functions return generators with 'yield from'
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000 || PHP_VERSION_ID >= 80000) die('skip: Test is for PHP 7'); ?>
--ENV--
DD_TRACE_DEBUG=1
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
?>
--EXPECT--
Cannot instrument generators on PHP 7.x
1337
42
0
Done
Successfully triggered flush with trace of size 2
