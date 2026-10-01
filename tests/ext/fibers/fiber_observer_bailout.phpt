--TEST--
Test fiber observing with bailout
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die("skip: Fibers are a PHP 8.1+ feature"); ?>
--INI--
fatal_error_backtraces=0
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

DDTrace\start_span();

function outer($fiber) {
    $fiber->resume();
}

function inFiber() {
    Fiber::suspend();
    DDTrace\Testing\trigger_error("Fatal error in fiber", E_ERROR);
}

DDTrace\trace_function("outer", function() {});
DDTrace\trace_function("inFiber", function() {
    echo "inFiber posthook\n";
});

register_shutdown_function(function() {
    DDTrace\close_span();
    dd_dump_spans();
});

$fiber = new Fiber(inFiber(...));
$fiber->start();

outer($fiber); // make something on the main stack the current observed frame

?>
--EXPECTF--
Fatal error: Fatal error in fiber in %s on line %d
inFiber posthook
spans(\DDTrace\SpanData) (1) {
  fiber_observer_bailout.php (fiber_observer_bailout.php, fiber_observer_bailout.php, cli) (error: Fatal error in fiber)
    error.type => E_ERROR
    error.message => Fatal error in fiber
    error.stack => #0 %s(%d): DDTrace\Testing\trigger_error()
#1 [internal function]: inFiber()
#2 %s(%d): Fiber->resume()
#3 %s(%d): outer()
#4 {main}
    inFiber (fiber_observer_bailout.php, inFiber, cli) (error: Fatal error in fiber)
      error.type => E_ERROR
      error.message => Fatal error in fiber
      error.stack => #0 %s(%d): DDTrace\Testing\trigger_error()
#1 [internal function]: inFiber()
#2 %s(%d): Fiber->resume()
#3 %s(%d): outer()
#4 {main}
    outer (fiber_observer_bailout.php, outer, cli)
}
