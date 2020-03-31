--TEST--
Functions that return by reference are instrumented
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
<?php if (PHP_VERSION_ID < 70000) die('skip: Unaltered VM dispatch required for return by ref on PHP 5'); ?>
--FILE--
<?php
use DDTrace\SpanData;

dd_trace_function('foo', function (SpanData $span, array $args, $retval) {
    $span->name = 'foo';
    var_dump($retval);
});

function &foo() {
    static $data = [];
    $data[] = 42;
    return $data;
}

$data = &foo();
$data[] = 1337;
foo();
var_dump($data);

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
array(1) {
  [0]=>
  int(42)
}
array(3) {
  [0]=>
  int(42)
  [1]=>
  int(1337)
  [2]=>
  int(42)
}
array(3) {
  [0]=>
  int(42)
  [1]=>
  int(1337)
  [2]=>
  int(42)
}
foo
foo
