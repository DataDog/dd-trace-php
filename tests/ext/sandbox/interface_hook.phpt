--TEST--
[Sandbox] Hook implementations of interface methods
--FILE--
<?php

DDTrace\hook_method("Ancestor", "Method", function() {
    echo "EARLY Ancestor HOOK\n";
});

DDTrace\hook_method("First", "Method", function() {
    echo "EARLY First HOOK\n";
});

// Ensure run-time resolving
if (true) {
    interface Ancestor {
        public function Method();
    }
}

class First implements Ancestor {
    public function Method() {
        echo "METHOD First\n";
    }
}

class Second implements Ancestor {
    public function Method() {
        echo "METHOD Second\n";
    }
}

DDTrace\hook_method("Ancestor", "Method", function() {
    echo "LATE Ancestor HOOK\n";
});

DDTrace\hook_method("First", "Method", function() {
    echo "LATE First HOOK\n";
});

(new First())->Method();
(new Second())->Method();

// must have no side effect on the Ancestor hook
dd_untrace("Method", "Second");
(new Second())->Method();

dd_untrace("Method", "Ancestor");
(new First())->Method();
(new Second())->Method();

?>
--EXPECT--
EARLY First HOOK
LATE First HOOK
EARLY Ancestor HOOK
LATE Ancestor HOOK
METHOD First
EARLY Ancestor HOOK
LATE Ancestor HOOK
METHOD Second
EARLY Ancestor HOOK
LATE Ancestor HOOK
METHOD Second
EARLY First HOOK
LATE First HOOK
METHOD First
METHOD Second
