--TEST--
[Sandbox regression] Untrace a function
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=spl_autoload_register
--FILE--
<?php

DDTrace\trace_function("spl_autoload_register", function() {
    echo "HOOK" . PHP_EOL;
});

spl_autoload_register(function($class) {
    return false;
});

spl_autoload_register(function($class) {
    return false;
});

dd_untrace("spl_autoload_register");

spl_autoload_register(function($class) {
    return false;
});

// Also testing that if a function does not exists dd_untrace does not throw
// an exception
dd_untrace("this_function_does_not_exist");

?>
--EXPECT--
HOOK
HOOK
