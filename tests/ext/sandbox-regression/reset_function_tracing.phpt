--TEST--
[Sandbox regression] Check a function can be untraced.
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php

dd_trace("spl_autoload_register", function() {
    echo "HOOK" . PHP_EOL;
    return call_user_func_array('spl_autoload_register', func_get_args());
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
