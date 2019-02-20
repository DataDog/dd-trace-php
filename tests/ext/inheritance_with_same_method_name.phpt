--TEST--
Ensure the `parent::` method is invoked from a sub class
--DESCRIPTION--
This bug was found from the Drupal 7 DBAL:
https://github.com/drupal/drupal/blob/bc60c9298a6b1a09c22bea7f5d87916902c27024/includes/database/sqlite/database.inc#L238
--FILE--
<?php

class Foo
{
    public function doStuff(/*$foo, array $bar = []*/)
    {
        return 42;
    }
}

class Bar extends Foo
{
    public function doStuff(/*$foo, array $bar = []*/)
    {
        return 1337;
    }

    public function parentDoStuff()
    {
        # Should return "42"
        return parent::doStuff(/*'foo', [1, 2, 3]*/);
    }

    public function myDoStuff()
    {
        # Should return "1337"
        return $this->doStuff(/*'bar', [4, 2]*/);
    }
}

$foo = new Foo;
echo "Base class:\n";
echo $foo->doStuff('foo') . "\n";

$bar = new Bar;
echo "Before tracing:\n";
echo $bar->parentDoStuff() . "\n";
echo $bar->myDoStuff() . "\n";

dd_trace('Foo', 'doStuff', function () {
    echo "**TRACED**\n";
    //return call_user_func_array([$this, 'doStuff'], func_get_args());
    return dd_trace_invoke_original();
});

/*
dd_trace('Bar', 'parentDoStuff', function () {
    var_dump(dd_trace_invoke_original());
    return call_user_func_array([$this, 'parentDoStuff'], func_get_args());
});
*/

echo "After tracing:\n";
echo $bar->parentDoStuff() . "\n";
echo $bar->myDoStuff() . "\n";
?>
--EXPECT--
Base class:
42
Before tracing:
42
1337
After tracing:
**TRACED**
42
1337
