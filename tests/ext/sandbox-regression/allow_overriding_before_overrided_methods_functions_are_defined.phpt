--TEST--
[Sandbox regression] Trace a function and method before it is defined
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
dd_trace_method("Test", "m", function($span, array $args, $retval) {
    echo 'HOOK ' . $retval;
});

dd_trace_function("fun", function($span, array $args, $retval) {
    echo 'HOOK ' . $retval;
});


class Test
{
    public function m()
    {
        return "METHOD" . PHP_EOL;
    }
}

function fun(){
    return 'FUNCTION' . PHP_EOL;
}

(new Test())->m();
fun();

?>
--EXPECT--
HOOK METHOD
HOOK FUNCTION
