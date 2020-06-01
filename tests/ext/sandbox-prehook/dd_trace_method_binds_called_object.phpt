--TEST--
[Prehook Regression] dd_trace_method() binds the called object to the tracing closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--INI--
ddtrace.traced_internal_functions=DatePeriod::getStartDate
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

dd_trace_method('Foo', 'testBinding', ['prehook' => function () {
    echo "Traced testBinding\n";
    var_dump($this);
}]);

dd_trace_method('DatePeriod', 'getStartDate', ['prehook' => function () {
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
