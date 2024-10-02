--TEST--
Test fiber observing with bailout
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die("skip: Fibers are a PHP 8.1+ feature"); ?>
--INI--
memory_limit=100M
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

DDTrace\start_span();

function outer($fiber) {
    $fiber->resume();
}

function inFiber() {
    Fiber::suspend();
    str_repeat("\0", 1 << 31);
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
Fatal error: Allowed memory size of %d bytes exhausted %s in %s on line %d
inFiber posthook
spans(\DDTrace\SpanData) (1) {
  fiber_observer_bailout.php (fiber_observer_bailout.php, fiber_observer_bailout.php, cli) (error: Allowed memory size of %d bytes exhausted %s)
    error.type => E_ERROR
    error.message => Allowed memory size of %d bytes exhausted %s
    error.stack => #0 %s(%d): str_repeat()
#1 [internal function]: inFiber()
#2 %s(%d): Fiber->resume()
#3 %s(%d): outer()
#4 {main}
    _dd.p.dm => -0
    _dd.p.tid => %s
    inFiber (fiber_observer_bailout.php, inFiber, cli) (error: Allowed memory size of %d bytes exhausted %s)
      error.type => E_ERROR
      error.message => Allowed memory size of %d bytes exhausted %s
      error.stack => #0 %s(%d): str_repeat()
#1 [internal function]: inFiber()
#2 %s(%d): Fiber->resume()
#3 %s(%d): outer()
#4 {main}
    outer (fiber_observer_bailout.php, outer, cli)
}
