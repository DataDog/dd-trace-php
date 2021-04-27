--TEST--
DDTrace\trace_method() binds the called object to the tracing closure
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=LimitIterator::getInnerIterator
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

DDTrace\trace_method('LimitIterator', 'getInnerIterator', function ($span) {
    echo "Traced LimitIterator::getInnerIterator\n";
    var_dump($this instanceof LimitIterator);
});

$foo = new Foo();
$foo->testBinding();

$inner = new ArrayIterator([1, 2]);
$limit = new LimitIterator($inner, 0, 1);
$limit->rewind();
assert($limit->valid());
$innerIterator = $limit->getInnerIterator();
assert($inner == $innerIterator);
?>
--EXPECTF--
Foo::testBinding()
Traced testBinding
object(Foo)#%d (0) {
}
Traced LimitIterator::getInnerIterator
bool(true)
