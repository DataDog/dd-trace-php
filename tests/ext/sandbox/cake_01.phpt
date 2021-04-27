--TEST--
Test that we don't have a sigsegv in a set-up similar to CakePHP
--FILE--
<?php

DDTrace\trace_method('DatadogDispatcher', 'dispatch', function () {});

class DatadogDispatcher
{
    // if I add a constructor I get a leak, but no segfault
    // public function __construct() {}

    public function dispatch() {}
    public function _stop() {}
}

function run() {
    $dispatcher = new DatadogDispatcher();
    $dispatcher->_stop($dispatcher->dispatch());
}

run();
echo "Done.\n";
?>
--EXPECT--
Done.

