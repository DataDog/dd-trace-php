--TEST--
[Prehook Regression] DDTrace\trace_method() binds the called object to the tracing closure
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

DDTrace\trace_method('Foo', 'testBinding', ['prehook' => function () {
    echo "Traced testBinding\n";
    var_dump($this);
}]);

DDTrace\trace_method('DatePeriod', 'getStartDate', ['prehook' => function () {
    echo "Traced getStartDate\n";
    var_dump($this instanceof DatePeriod);
}]);

$foo = new Foo();
$foo->testBinding();

$period = new DatePeriod('R7/2019-08-21T00:00:00Z/P1D');
$period->getStartDate();
?>
--EXPECTF--
Traced testBinding
object(Foo)#%d (0) {
}
Foo::testBinding()
Traced getStartDate
bool(true)
