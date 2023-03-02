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

class Base {
    use Ancestor;

    public function Method() {
        echo "METHOD Base\n";
    }
}

DDTrace\hook_method("Ancestor", "Method", function() {
    echo "LATE Ancestor HOOK\n";
});

DDTrace\hook_method("Base", "Method", function() {
    echo "LATE Base HOOK\n";
});

(new Base())->Method();

dd_untrace("Method", "Ancestor");
(new Base())->Method();

?>
--EXPECT--
EARLY Base HOOK
LATE Base HOOK
METHOD Base
EARLY Base HOOK
LATE Base HOOK
METHOD Base
