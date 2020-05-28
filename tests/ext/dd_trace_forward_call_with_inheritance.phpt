--TEST--
The original method call is invoked from a sub class
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php

class Foo
{
    public function doStuff($foo, array $bar = [])
    {
        return '[' . $foo . '] ' . array_sum($bar);
    }
}

class Bar extends Foo
{
    public function doStuff($foo, array $bar = [])
    {
        return 'BAR ' . array_sum($bar) . ' {' . $foo . '}';
    }

    public function parentDoStuff()
    {
        return parent::doStuff('parent', [1, 2, 3]);
    }

    public function myDoStuff()
    {
        return $this->doStuff('mine', [4, 2, 6]);
    }
}

$foo = new Foo;
$bar = new Bar;

/* Cannot call a function while it is not traced and later expect it to trace
echo "=== Before tracing ===\n";
echo $foo->doStuff('foo') . "\n";
echo $bar->parentDoStuff() . "\n";
echo $bar->myDoStuff() . "\n";
*/

dd_trace('Foo', 'doStuff', function () {
    echo "**TRACED**\n";
    return dd_trace_forward_call();
});

echo "=== After tracing ===\n";
echo $foo->doStuff('foo') . "\n";
echo $bar->parentDoStuff() . "\n";
echo $bar->myDoStuff() . "\n";
?>
--EXPECT--
=== After tracing ===
**TRACED**
[foo] 0
**TRACED**
[parent] 6
**TRACED**
BAR 12 {mine}
