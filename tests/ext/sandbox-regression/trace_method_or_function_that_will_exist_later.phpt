--TEST--
[Sandbox regression] Methods and functions are traced before defined
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php

dd_trace_method("Test", "some_method", function($s, $a, $retval){
    echo "HOOK " . $retval;
});

dd_trace_function("some_function", function($s, $a, $retval){
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
