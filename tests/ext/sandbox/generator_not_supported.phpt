--TEST--
Generators are not supported on PHP versions < 7.1
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: Generators were added in PHP 5.5'); ?>
<?php if (PHP_VERSION_ID < 70000) die('skip: Requires unaltered VM dispatch'); /* Remove when unaltered VM dispatch added in PHP 5 */ ?>
<?php if (PHP_VERSION_ID >= 70100) die('skip: Test is for PHP versions less than 7.1'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php
use DDTrace\SpanData;

function getResults() {
    for ($i = 0; $i < 3; $i++) {
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

dd_trace_function('getResults', function(SpanData $s, $a, $retval) {
    $s->name = 'getResults';
    $s->resource = $retval;
});

dd_trace_function('doSomething', function(SpanData $s, $a, $retval) {
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
Cannot instrument generators for PHP versions < 7.1
0
1
2
Done
doSomething, Done
