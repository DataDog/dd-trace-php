--TEST--
[Sandbox regression] Methods and functions are traced before definedx
--FILE--
<?php

DDTrace\trace_method("Test", "some_method", function($s, $a, $retval){
    echo "HOOK " . $retval;
});

DDTrace\trace_function("some_function", function($s, $a, $retval){
    echo 'HOOK2 ' . $retval;
});

function some_function($a) {
    return "FUNCTION " . $a .PHP_EOL;
}

class Test
{
    public function some_method($a)
    {
        return  "METHOD " . $a . PHP_EOL;
    }
}

$test = new Test();
$test->some_method('a');
some_function('b');

?>
--EXPECT--
HOOK METHOD a
HOOK2 FUNCTION b
