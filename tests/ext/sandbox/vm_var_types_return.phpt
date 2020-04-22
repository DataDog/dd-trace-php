--TEST--
VM variable types are handled properly for return
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

dd_trace_function('retval_IS_CONST', function (SpanData $span, array $args, $retval) {
    $span->name = 'retval_IS_CONST';
    $span->resource = $retval;
    var_dump($retval);
});

dd_trace_function('retval_IS_CV', function (SpanData $span, array $args, $retval) {
    $span->name = 'retval_IS_CV';
    $span->resource = $retval;
    var_dump($retval);
});

dd_trace_function('retval_IS_VAR', function (SpanData $span, array $args, $retval) {
    $span->name = 'retval_IS_VAR';
    $span->resource = $retval;
    var_dump($retval);
});

dd_trace_function('retval_IS_TMP_VAR', function (SpanData $span, array $args, $retval) {
    $span->name = 'retval_IS_TMP_VAR';
    $span->resource = $retval;
    var_dump($retval);
});

function retval_IS_CONST() {
    return 42;
}

function retval_IS_CV() {
    $a = 'IS_CV';
    return $a;
}

function retval_IS_VAR() {
    return array_sum([50, 50]);
}

function retval_IS_TMP_VAR() {
    return 100 + array_sum([50, 50]);
}

echo retval_IS_CONST() . PHP_EOL;
echo retval_IS_CV() . PHP_EOL;
echo retval_IS_VAR() . PHP_EOL;
echo retval_IS_TMP_VAR() . PHP_EOL;

array_map(function($span) {
    echo $span['name'];
    echo isset($span['resource']) ? ', ' . $span['resource'] : '';
    echo PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
int(42)
42
string(5) "IS_CV"
IS_CV
int(100)
100
int(200)
200
retval_IS_TMP_VAR, 200
retval_IS_VAR, 100
retval_IS_CV, IS_CV
retval_IS_CONST, 42
