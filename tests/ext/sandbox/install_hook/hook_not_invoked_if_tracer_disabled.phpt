--TEST--
Ensure hooks are not invoked after the tracer has been disabled
--FILE--
<?php

function foo() {}
DDTrace\install_hook("foo", function($h) {
    print "Invoked\n";
    ini_set("datadog.trace.enabled", 0);
    $h->span(); // must not crash
}, function() {
    print "Skipped\n";
});
foo();
foo();

?>
--EXPECT--
Invoked