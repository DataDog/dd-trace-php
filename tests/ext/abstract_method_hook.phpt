--TEST--
Check abstract method tracing.
--FILE--
<?php

abstract class SomeAbstractClass
{
    abstract public function m();
}

class Test extends SomeAbstractClass
{
    public function m()
    {
        echo "METHOD" . PHP_EOL;
    }
}

dd_trace(SomeAbstractClass::class, "m", function() {
    $this->m();
    echo "HOOK" . PHP_EOL;
});

(new Test())->m();

?>
--EXPECT--
METHOD
HOOK
