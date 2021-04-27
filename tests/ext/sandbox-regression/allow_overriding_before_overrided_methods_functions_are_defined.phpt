--TEST--
[Sandbox regression] Trace a function and method before it is defined
--FILE--
<?php
DDTrace\trace_method("Test", "m", function($span, array $args, $retval) {
    echo 'HOOK ' . $retval;
});

DDTrace\trace_function("fun", function($span, array $args, $retval) {
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
