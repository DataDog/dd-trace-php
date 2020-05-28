--TEST--
Check that a method and a function can be traced before it exists.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php

dd_trace("Test", "some_method", function($a){
    return "HOOK " . $this->some_method($a);
});

dd_trace("some_function", function($a){
    return 'HOOK2 ' . some_function($a);
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
echo $test->some_method('a');
echo some_function('b');

?>
--EXPECT--
HOOK METHOD a
HOOK2 FUNCTION b
