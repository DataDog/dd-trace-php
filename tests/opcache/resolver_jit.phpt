--TEST--
Verify functionality depending on class linking observation also works with JIT
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.jit_buffer_size=256M
--FILE--
<?php

DDTrace\hook_method('BaseClass', 'speak',
    function ($This, $scope, $args) {
        echo "{$scope}::speak hooked in BaseClass.\n";
    }
);

DDTrace\hook_method('ChildClass', 'speak',
    function ($This, $scope, $args) {
        echo "{$scope}::speak hooked in ChildClass.\n";
    }
);

class BaseClass
{
    public static function speak()
    {
        echo "I have spoken.\n";
    }
}

BaseClass::speak();

// delay ChildClass invocation until runtime
if (true) {
    final class ChildClass extends BaseClass
    {
    }
}

ChildClass::speak();

?>
--EXPECT--
BaseClass::speak hooked in BaseClass.
I have spoken.
ChildClass::speak hooked in ChildClass.
ChildClass::speak hooked in BaseClass.
I have spoken.
