--TEST--
[Sandbox regression] Private and protected properties are accessed from a tracing closure
--FILE--
<?php
class Test
{
    private $value_private = '__value__private__';
    protected $value_protected = '__value__protected__';

    public function m()
    {
        echo "METHOD" . PHP_EOL;
    }
}

DDTrace\trace_method("Test", "m", function() {
    echo "PRIVATE PROPERTY IN HOOK " . $this->value_private . PHP_EOL;
    echo "PROTECTED PROPERTY IN HOOK " . $this->value_protected . PHP_EOL;
});

(new Test())->m();

?>
--EXPECT--
METHOD
PRIVATE PROPERTY IN HOOK __value__private__
PROTECTED PROPERTY IN HOOK __value__protected__
