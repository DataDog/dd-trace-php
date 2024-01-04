--TEST--
remove_hook() with class argument
--FILE--
<?php

interface Elder {
    function foo();
}

$id = DDTrace\install_hook("Elder::foo", function () { print "HOOKED: " . static::class . "\n"; });

if (time()) {
    abstract class Child implements Elder {}

    class GrandChild extends Child {
        function foo() {
            print static::class . "\n";
        }
    }
}

DDTrace\remove_hook($id, "GrandChild");

(new GrandChild)->foo(); // no hook

?>
--EXPECT--
GrandChild
