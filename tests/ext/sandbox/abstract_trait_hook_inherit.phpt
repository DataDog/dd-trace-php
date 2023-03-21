--TEST--
[Sandbox] Hook implementations of abstract trait methods are not supported
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
    trait Ancestor {
        public abstract function Method();
    }
}

abstract class Base {
    use Ancestor;
}

class Child extends Base {
    use Ancestor;

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

dd_untrace("Method", "Ancestor");
(new Child())->Method();

?>
--EXPECT--
EARLY Base HOOK
LATE Base HOOK
METHOD Child
EARLY Base HOOK
LATE Base HOOK
METHOD Child
