--TEST--
remove_hook() with class argument
--FILE--
<?php

interface Elder {
    function foo();
}

class Other implements Elder {
    function foo() {
        print static::class . "\n";
    }
}

class OtherChild extends Other {}

$id = DDTrace\install_hook("Elder::foo", function () { print "HOOKED: " . static::class . "\n"; });
DDTrace\remove_hook($id, "Child");

(new Other)->foo();

if (time()) {
    abstract class Child implements Elder {}

    class GrandChild extends Child {
        function foo() {
            print static::class . "\n";
        }
    }

    class GreatGrandChild extends GrandChild {
    }
}

(new GrandChild)->foo(); // no hook
(new GreatGrandChild)->foo(); // no hook

DDTrace\remove_hook($id, "Other");
(new Other)->foo(); // also removed now
(new OtherChild)->foo(); // also removed now

?>
--EXPECT--
HOOKED: Other
Other
GrandChild
GreatGrandChild
Other
OtherChild
