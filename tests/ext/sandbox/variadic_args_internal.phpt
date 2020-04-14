--TEST--
Variadic arguments are passed to tracing closure for internal functions
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: PHP 5.4 not supported'); ?>
--FILE--
<?php
dd_trace_function('sscanf', function (DDTrace\SpanData $s, array $args) {
    var_dump($args);
});

$ret = sscanf("42\tFoo Bar", "%d\t%s %s", $id, $first, $last);
var_dump($ret);
?>
--EXPECT--
array(5) {
  [0]=>
  string(10) "42	Foo Bar"
  [1]=>
  string(8) "%d	%s %s"
  [2]=>
  int(42)
  [3]=>
  string(3) "Foo"
  [4]=>
  string(3) "Bar"
}
int(3)
