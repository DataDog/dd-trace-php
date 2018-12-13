--TEST--
Check interface method tracing.
--FILE--
<?php

interface SomeInterface
{
    public function m();
}

class Test implements SomeInterface
{
    public function m()
    {
        echo "METHOD" . PHP_EOL;
    }
}

dd_trace(SomeInterface::class, "m", function() {
    $this->m();
    echo "HOOK" . PHP_EOL;
});

(new Test())->m();

?>
--EXPECT--
METHOD
HOOK
