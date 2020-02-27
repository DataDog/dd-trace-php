--TEST--
[Prehook] Variadic arguments are passed to tracing closure for internal functions
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--FILE--
<?php
dd_trace_function('array_unshift', ['prehook' => function (DDTrace\SpanData $s, array $args) {
    var_dump($args);
}]);

$queue = ['Foo', 'Bar'];
array_unshift($queue, 'Baz', 42, true);
var_dump($queue);
?>
--EXPECT--
array(4) {
  [0]=>
  array(2) {
    [0]=>
    string(3) "Foo"
    [1]=>
    string(3) "Bar"
  }
  [1]=>
  string(3) "Baz"
  [2]=>
  int(42)
  [3]=>
  bool(true)
}
array(5) {
  [0]=>
  string(3) "Baz"
  [1]=>
  int(42)
  [2]=>
  bool(true)
  [3]=>
  string(3) "Foo"
  [4]=>
  string(3) "Bar"
}
