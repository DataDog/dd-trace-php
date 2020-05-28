--TEST--
Override function/method before its defined
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php
dd_trace("Test", "m", function() {
    return 'HOOK ' . $this->m();
});

dd_trace("fun", function() {
    return 'HOOK ' . fun();
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

echo (new Test())->m();
echo fun();

?>
--EXPECT--
HOOK METHOD
HOOK FUNCTION
