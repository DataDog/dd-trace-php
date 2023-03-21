--TEST--
[Sandbox] Hook implementations of interface methods on all direct implementors
--FILE--
<?php

DDTrace\hook_method("Ancestor", "Method", [
    "prehook" => function() {
        echo "EARLY Ancestor HOOK\n";
    },
    "recurse" => true,
]);

// Ensure run-time resolving
if (true) {
    interface Ancestor {
        public function Method();
    }
}

class Base implements Ancestor {
    public function Method() {
        echo "METHOD Base\n";
    }
}

class Child extends Base implements Ancestor {
    public function Method() {
        echo "METHOD Child\n";
        parent::Method();
    }
}

DDTrace\hook_method("Ancestor", "Method", [
    "prehook" => function() {
        echo "LATE Ancestor HOOK\n";
    },
    "recurse" => true,
]);

(new Child())->Method();

dd_untrace("Method", "Ancestor");
(new Child())->Method();

?>
--EXPECT--
EARLY Ancestor HOOK
LATE Ancestor HOOK
METHOD Child
EARLY Ancestor HOOK
LATE Ancestor HOOK
METHOD Base
METHOD Child
METHOD Base
