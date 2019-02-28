--TEST--
A private method can be used as callback with dd_trace_forward_call()
--FILE--
<?php

class Foo
{
    private static function privateRegister()
    {
    }

    public function register()
    {
        spl_autoload_register(__CLASS__.'::privateRegister');
    }
}

$a = new Foo();
echo "Before:\n";
$a->register();

dd_trace('spl_autoload_register', function () {
    echo "**TRACED**\n";
    //return call_user_func_array('spl_autoload_register', func_get_args());
    return dd_trace_forward_call();
});

echo "After:\n";
$a->register();
?>
--EXPECT--
Before:
After:
**TRACED**
