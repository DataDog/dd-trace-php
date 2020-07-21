--TEST--
Variadic arguments are passed to tracing closure when no arguments exist in function signature
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: PHP 5.4 not supported'); ?>
--FILE--
<?php
function bar($a, $b, $c) {
    return $a . ', ' . $b . ', ' . $c;
}

function foo() {
    $args = func_get_args();
    $retval = bar($args[0], $args[1], $args[2]);
    return $retval;
}

DDTrace\trace_function('foo', function ($span, array $args) {
    $span->name = $span->resource = 'foo';
    var_dump($args);
});

echo foo('Cats', 'Dogs', 'Birds') . PHP_EOL;
?>
--EXPECT--
array(3) {
  [0]=>
  string(4) "Cats"
  [1]=>
  string(4) "Dogs"
  [2]=>
  string(5) "Birds"
}
Cats, Dogs, Birds
