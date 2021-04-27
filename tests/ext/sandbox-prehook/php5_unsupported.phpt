--TEST--
[Prehook] Not supported on PHP 5
--SKIPIF--
<?php if (PHP_VERSION_ID >= 70000) die('skip: PHP 5 only test'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php
use DDTrace\SpanData;

var_dump(DDTrace\trace_function('foo', [
    'prehook' => function (SpanData $span, array $args) {
        echo 'foo() prehook' . PHP_EOL;
        var_dump($args);
    }
]));

function foo($a) {
    var_dump(func_get_args());
    $a = 'Dogs';
    var_dump(func_get_args());
}

foo('Cats');
?>
--EXPECT--
'prehook' not supported on PHP 5
bool(false)
array(1) {
  [0]=>
  string(4) "Cats"
}
array(1) {
  [0]=>
  string(4) "Cats"
}
