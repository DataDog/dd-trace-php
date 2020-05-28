--TEST--
Check a function can be untraced.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php

dd_trace("register", function() {
    echo "HOOK" . PHP_EOL;
    return dd_trace_forward_call();
});

function register() {}

register();
register();

echo "unregister\n";
dd_untrace("register");

register();

// Also testing that if a function does not exists dd_untrace does not throw
// an exception
dd_untrace("this_function_does_not_exist");

echo "Done.\n";

?>
--EXPECT--
HOOK
HOOK
unregister
Done.

