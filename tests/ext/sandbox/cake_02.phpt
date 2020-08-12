--TEST--
Test that we don't leak in a set-up similar to CakePHP
--FILE--
<?php

DDTrace\trace_method('DatadogDispatcher', 'dispatch', function () {});

class DatadogDispatcher
{
    // it is intentional that these things do nothing; we used to leak
    public function __construct() {}

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

