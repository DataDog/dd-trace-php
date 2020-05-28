--TEST--
Check object's private and protected properties can be accessed from a callback.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
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

dd_trace("Test", "m", function() {
    $this->m();
    echo "PRIVATE PROPERTY IN HOOK " . $this->value_private . PHP_EOL;
    echo "PROTECTED PROPERTY IN HOOK " . $this->value_protected . PHP_EOL;
});

(new Test())->m();

?>
--EXPECT--
METHOD
PRIVATE PROPERTY IN HOOK __value__private__
PROTECTED PROPERTY IN HOOK __value__protected__
