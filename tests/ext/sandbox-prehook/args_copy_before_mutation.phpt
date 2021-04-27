--TEST--
[Prehook] Arguments are copied before mutation can occur
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--FILE--
<?php
use DDTrace\SpanData;

DDTrace\trace_function('foo', [
    'prehook' => function (SpanData $span, array $args) {
        echo 'foo() prehook' . PHP_EOL;
        var_dump($args);
    }
]);

function foo($a) {
    var_dump(func_get_args());
    $a = 'Dogs';
    var_dump(func_get_args());
}

foo('Cats');
?>
--EXPECT--
foo() prehook
array(1) {
  [0]=>
  string(4) "Cats"
}
array(1) {
  [0]=>
  string(4) "Cats"
}
array(1) {
  [0]=>
  string(4) "Dogs"
}
