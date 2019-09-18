--TEST--
Exceptions from original call rethrown in tracing closure (PHP 7)
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
<?php if (PHP_VERSION_ID < 70000) die('skip PHP 5 tested in separate test'); ?>
--FILE--
<?php

function a(){
    echo "a()\n";
    throw new Exception('Oops!');
}

dd_trace_function('a', function($s, $args, $r, $ex) {
    $s->name = 'a';
    throw $ex;
});

try {
    a();
} catch (Exception $e) {
    //
}

array_map(function($span) {
    printf(
        "%s with exception: %s\n",
        $span['name'],
        $span['meta']['error.msg']
    );
}, dd_trace_serialize_closed_spans());
?>
--EXPECTF--
a()
a with exception: Oops!
