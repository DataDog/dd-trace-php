--TEST--
Check that a function can be traced before it exists.
--FILE--
<?php

dd_trace("Test", "some_method", function($a){
    $this->some_method($a);
    echo "HOOK " . $a . PHP_EOL;
});

class Test
{
    public function some_method($a)
    {
        echo "METHOD " . $a . PHP_EOL;
    }
}

$test = new Test();
$test->some_method('a');

?>
--EXPECT--
METHOD a
HOOK a
