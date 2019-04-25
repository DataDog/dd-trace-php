--TEST--
A private method can be used as callback with dd_trace_forward_call()
--FILE--
<?php

class Foo
{
    protected static function protectedRegister()
    {
    }

    private static function privateRegister()
    {
    }

    public function register()
    {
        spl_autoload_register(__CLASS__.'::protectedRegister');
        spl_autoload_register(__CLASS__.'::privateRegister');
    }
}

$a = new Foo();
echo "Before:\n";
$a->register();

dd_trace('spl_autoload_register', function () {
    echo "**TRACED**\n";
    return dd_trace_forward_call();
});

echo "After:\n";
$a->register();
?>
--EXPECT--
Before:
After:
**TRACED**
**TRACED**
