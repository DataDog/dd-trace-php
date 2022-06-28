--TEST--
[Prehook] Arguments are copied before mutation can occur
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
