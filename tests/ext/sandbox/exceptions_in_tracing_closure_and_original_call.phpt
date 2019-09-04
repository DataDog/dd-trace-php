--TEST--
Exceptions thrown in tracing closure and original call
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip PHP 5 tested in separate test'); ?>
--FILE--
<?php
class SubException extends Exception { }

function a(){
    echo "a()\n";
    throw new SubException('Oops!');
}

dd_trace_function('a', function($span, $args, $r, $ex) {
    var_dump($ex instanceof SubException);
    throw new Exception('!');
});

try {
    a();
} catch (SubException $ex) {
   echo "Caught SubException\n";
}
echo "Recovery successful\n";
?>
--EXPECTF--
a()
bool(true)
Caught SubException
Recovery successful
