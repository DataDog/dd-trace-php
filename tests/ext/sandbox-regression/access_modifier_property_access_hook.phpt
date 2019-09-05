--TEST--
[Sandbox regression] Private and protected properties are accessed from a tracing closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
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

dd_trace_method("Test", "m", function() {
    echo "PRIVATE PROPERTY IN HOOK " . $this->value_private . PHP_EOL;
    echo "PROTECTED PROPERTY IN HOOK " . $this->value_protected . PHP_EOL;
});

(new Test())->m();

?>
--EXPECT--
METHOD
PRIVATE PROPERTY IN HOOK __value__private__
PROTECTED PROPERTY IN HOOK __value__protected__
