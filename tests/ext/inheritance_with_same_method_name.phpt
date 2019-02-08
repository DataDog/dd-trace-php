--TEST--
Ensure the `parent::` method is invoked from a sub class
--DESCRIPTION--
This bug was found from the Drupal 7 DBAL:
https://github.com/drupal/drupal/blob/bc60c9298a6b1a09c22bea7f5d87916902c27024/includes/database/sqlite/database.inc#L238
--FILE--
<?php

class Foo
{
    public function doStuff()
    {
        return 42;
    }
}

class Bar extends Foo
{
    public function doStuff()
    {
        return 1337;
    }

    public function parentDoStuff()
    {
        # Should return "42"
        return parent::doStuff();
    }
}

$bar = new Bar;
echo "Before tracing:\n";
dd_trace_noop();
echo $bar->parentDoStuff() . "\n";

dd_trace('Foo', 'doStuff', function () {
    var_dump(dd_trace_invoke_original());
    return call_user_func_array([$this, 'doStuff'], func_get_args());
});

echo "After tracing:\n";
dd_trace_noop();
echo $bar->parentDoStuff() . "\n";
?>
--EXPECT--
Before tracing:
42
After tracing:
42
