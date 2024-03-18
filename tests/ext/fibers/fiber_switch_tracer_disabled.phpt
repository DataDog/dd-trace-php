--TEST--
Test executing fibers with tracer fully disabled
--INI--
ddtrace.disable=1
--FILE--
<?php

function fiber() {
    Fiber::suspend(1);
    return 2;
}

$fiber = new Fiber(fiber(...));
var_dump($fiber->start());
$fiber->resume();
var_dump($fiber->getReturn());

?>
--EXPECT--
int(1)
int(2)
