--TEST--
Assert bailouts are gracefully handled within class autoloading
--SKIPIF--
<?php if (PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 70400) die('skip: Bailing out in autoloaders is fundamentally broken in PHP 7.3'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
--FILE--
<?php

ddtrace\trace_function("x", function() { class C extends D {} print "Should not appear...\n"; });

function x() {}

spl_autoload_register(function() use (&$s) {
    if ($s) {
        trigger_error("No D", E_USER_ERROR);
    } else {
        $s = true;
        x();
        class B {}
        print "Leaving Autoloader\n";
    }
});

class A extends B {}

?>
--EXPECTF--
[ddtrace] [warning] Error raised in ddtrace's closure defined at %s:%d for x(): No D in %s
Leaving Autoloader
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
