--TEST--
Test fiber observing
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die("skip: Fibers are a PHP 8.1+ feature"); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/../sandbox/dd_dumper.inc';

$primarySpan = DDTrace\start_span();

function otherFiber() {
    Fiber::suspend();
    throw new Exception("ex");
}

function inFiber() {
    global $primarySpan;
    global $otherFiber;

    echo "The span stack after a fiber start is not equal to the primary one: "; var_dump($primarySpan->stack != DDTrace\active_stack());

    $otherFiber = new Fiber(otherFiber(...));
    $otherFiber->start();

    Fiber::suspend(123);

    echo "The span stack after a fiber resumption is not equal to the primary one: "; var_dump($primarySpan->stack != DDTrace\active_stack());
}

DDTrace\trace_method("Fiber", "start", function() {
    echo "Hook: Fiber->start\n";
});
DDTrace\trace_method("Fiber", "suspend", [
    "posthook" => function() {
        echo "Hook: Fiber::suspend\n";
    },
    "recurse" => true,
]);
DDTrace\trace_method("Fiber", "resume", function() {
    echo "Hook: Fiber->resume\n";
});
DDTrace\trace_function("inFiber", function() {
    echo "Hook: inFiber\n";
});
DDTrace\trace_function("otherFiber", function() {
    echo "Hook: otherFiber\n";
});

$fiber = new Fiber(inFiber(...));
$fiber->start();

$fiber->resume();

try {
    $otherFiber->resume();
} catch (Exception $e) {
    echo "Caught {$e->getMessage()}\n";
}

DDTrace\close_span();
dd_dump_spans();

?>
--EXPECTF--
The span stack after a fiber start is not equal to the primary one: bool(true)
Hook: Fiber->start
Hook: Fiber::suspend
The span stack after a fiber resumption is not equal to the primary one: bool(true)
Hook: inFiber
Hook: Fiber->resume
Hook: Fiber::suspend
Hook: otherFiber
Hook: Fiber->resume
Caught ex
spans(\DDTrace\SpanData) (1) {
  fiber_stack_switch.php (fiber_stack_switch.php, fiber_stack_switch.php, cli)
    process_id => %d
    _dd.p.dm => -1
    Fiber.start (fiber_stack_switch.php, Fiber.start, cli)
      inFiber (fiber_stack_switch.php, inFiber, cli)
        otherFiber (fiber_stack_switch.php, otherFiber, cli) (error: Uncaught Exception: ex in %s:%d)
          error.message => Uncaught Exception: ex in %s:%d
          error.type => Exception
          error.stack => #0 [internal function]: otherFiber()
#1 %s(%d): Fiber->resume()
#2 {main}
          Fiber.suspend (fiber_stack_switch.php, Fiber.suspend, cli)
        Fiber.suspend (fiber_stack_switch.php, Fiber.suspend, cli)
    Fiber.resume (fiber_stack_switch.php, Fiber.resume, cli)
    Fiber.resume (fiber_stack_switch.php, Fiber.resume, cli) (error: Uncaught Exception: ex in %s:%d)
      error.message => Uncaught Exception: ex in %s:%d
      error.type => Exception
      error.stack => #0 [internal function]: otherFiber()
#1 %s(%d): Fiber->resume()
#2 {main}
}
