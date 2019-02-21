--TEST--
Ensure the `parent::` method is invoked from a sub class
--DESCRIPTION--
This bug was found from the Drupal 7 DBAL:
https://github.com/drupal/drupal/blob/bc60c9298a6b1a09c22bea7f5d87916902c27024/includes/database/sqlite/database.inc#L238
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

echo "=== Before tracing ===\n";
echo $foo->doStuff('foo') . "\n";
echo $bar->parentDoStuff() . "\n";
echo $bar->myDoStuff() . "\n";

dd_trace('Foo', 'doStuff', function () {
    echo "**TRACED**\n";
    //return call_user_func_array([$this, 'doStuff'], func_get_args());
    return dd_trace_invoke_original();
});

echo "=== After tracing ===\n";
echo $foo->doStuff('foo') . "\n";
echo $bar->parentDoStuff() . "\n";
echo $bar->myDoStuff() . "\n";
?>
--EXPECT--
=== Before tracing ===
[foo] 0
[parent] 6
BAR 12 {mine}
=== After tracing ===
**TRACED**
[foo] 0
**TRACED**
[parent] 6
BAR 12 {mine}
