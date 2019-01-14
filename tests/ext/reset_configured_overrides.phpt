--TEST--
Configured overrides can be safely reset.
--FILE--
<?php
class Test {
    public function m(){
        return "METHOD" . PHP_EOL;
    }
}

dd_trace("Test", "m", function(){
    return  $this->m() . "METHOD HOOK" . PHP_EOL;
});

function test(){
    return "FUNCTION" . PHP_EOL;
}

dd_trace("test", function(){
    return test() . "FUNCTION HOOK" . PHP_EOL;
});

$object = new Test();
echo $object->m();
echo test();

echo (dd_trace_reset() ? "TRUE": "FALSE") . PHP_EOL;

echo $object->m();
echo test();

dd_trace("Test", "m", function(){
    return  $this->m() . "METHOD HOOK2" . PHP_EOL;
});

dd_trace("test", function(){
    return test() . "FUNCTION HOOK2" . PHP_EOL;
});

echo $object->m();
echo test();

?>
--EXPECT--
METHOD
METHOD HOOK
FUNCTION
FUNCTION HOOK
TRUE
METHOD
FUNCTION
METHOD
METHOD HOOK2
FUNCTION
FUNCTION HOOK2
