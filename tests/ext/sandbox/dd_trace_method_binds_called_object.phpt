--TEST--
dd_trace_method() binds the called object to the tracing closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
date_default_timezone_set('UTC');

class Foo
{
    public function testBinding()
    {
        echo "Foo::testBinding()\n";
    }
}

dd_trace_method('Foo', 'testBinding', function () {
    echo "Traced testBinding\n";
    var_dump($this);
});

dd_trace_method('DatePeriod', 'getStartDate', function () {
    echo "Traced getStartDate\n";
    var_dump($this instanceof DatePeriod);
});

$foo = new Foo();
$foo->testBinding();

$period = new DatePeriod('R7/2019-08-21T00:00:00Z/P1D');
$period->getStartDate();
?>
--EXPECTF--
Foo::testBinding()
Traced testBinding
object(Foo)#%d (0) {
}
Traced getStartDate
bool(true)
