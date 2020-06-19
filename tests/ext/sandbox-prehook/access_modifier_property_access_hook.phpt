--TEST--
[Prehook regression] Private and protected properties are accessed from a tracing closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
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

DDTrace\trace_method("Test", "m", ['prehook' => function() {
    echo "PRIVATE PROPERTY IN HOOK " . $this->value_private . PHP_EOL;
    echo "PROTECTED PROPERTY IN HOOK " . $this->value_protected . PHP_EOL;
}]);

(new Test())->m();
?>
--EXPECT--
PRIVATE PROPERTY IN HOOK __value__private__
PROTECTED PROPERTY IN HOOK __value__protected__
METHOD
