--TEST--
DDTrace\trace_method() binds the called object to the tracing closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=DatePeriod::getStartDate
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

DDTrace\trace_method('Foo', 'testBinding', function ($span) {
    echo "Traced testBinding\n";
    $span->name = $span->resource = 'Foo.testBinding';
    $span->service = 'phpt';
    var_dump($this);
});

DDTrace\trace_method('DatePeriod', 'getStartDate', function ($span) {
    echo "Traced getStartDate\n";
    var_dump($this instanceof DatePeriod);
    $span->name = $span->resource = 'DatePeriod.getStartDate';
    $span->service = 'phpt';
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
