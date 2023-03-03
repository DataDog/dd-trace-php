--TEST--
[Sandbox] Hook implementations of interface methods with multiple parent abstract methods of the same name
--FILE--
<?php

DDTrace\hook_method("Ancestor", "Method", function() {
    echo "EARLY Ancestor HOOK\n";
});

DDTrace\hook_method("Base", "Method", function() {
    echo "EARLY Base HOOK\n";
});

// Ensure run-time resolving
if (true) {
    interface Ancestor {
        public function Method();
    }

    abstract class Base {
        public abstract function Method();
    }
}

class Child extends Base implements Ancestor {
    public function Method() {
        echo "METHOD Child\n";
    }
}

DDTrace\hook_method("Ancestor", "Method", function() {
    echo "LATE Ancestor HOOK\n";
});

DDTrace\hook_method("Base", "Method", function() {
    echo "LATE Base HOOK\n";
});

(new Child())->Method();

dd_untrace("Method", "Base");
(new Child())->Method();

?>
--EXPECT--
EARLY Base HOOK
LATE Base HOOK
EARLY Ancestor HOOK
LATE Ancestor HOOK
METHOD Child
EARLY Ancestor HOOK
LATE Ancestor HOOK
METHOD Child
