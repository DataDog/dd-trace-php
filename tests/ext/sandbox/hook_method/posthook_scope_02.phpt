--TEST--
hook_method posthook is called with the correct scope (parent)
--FILE--
<?php

DDTrace\hook_method('BaseClass', 'speak',
    null,
    function ($This, $scope, $args) {
        echo "{$scope}::speak hooked.\n";
    }
);

class BaseClass
{
    public static function speak()
    {
        echo "I have spoken.\n";
    }
}

final class ChildClass extends BaseClass
{
    public static function speak()
    {
        parent::speak();
    }
}

final class Orthogonal
{
    public static function run()
    {
        ChildClass::speak();
    }
}

Orthogonal::run();
?>
--EXPECT--
I have spoken.
ChildClass::speak hooked.
