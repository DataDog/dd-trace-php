--TEST--
remove_hook() with class argument
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php

interface Elder {
    function foo();
}

abstract class Other implements Elder {
    function foo() {
        print static::class . "\n";
    }
}

class OtherChild extends Other {
    function foo() {
        print static::class . "\n";
    }
}

$id = DDTrace\install_hook("Elder::foo", function () { print "HOOKED: " . static::class . "\n"; });
DDTrace\remove_hook($id, "Child");

(new OtherChild)->foo();

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

print("\n---\n");
DDTrace\remove_hook($id, "Elder");
(new OtherChild)->foo(); // also removed now
print("\n---\n");
(new OtherChild)->foo(); // also removed now

?>
--EXPECT--
HOOKED: Other
Other
GrandChild
GreatGrandChild
Other
OtherChild
