<?php

use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverSuspension;

/*
DDTrace\trace_method(AbstractDriver::class, "tick", function (DDTrace\SpanData $span) {
    $span->metrics += $this->getInfo();
});
*/

function closureName(\Closure $closure) {
    $reflectionCb = new ReflectionFunction($closure);
    return $reflectionCb->getName() == "{closure}" ? "{$reflectionCb->getFileName()}:{$reflectionCb->getStartLine()}" : $reflectionCb->name;
}

$fiberStartMap = new WeakMap;
DDTrace\hook_method(AbstractDriver::class, "invokeMicrotasks", function (AbstractDriver $that) use ($fiberStartMap) {
    (function() use ($fiberStartMap) {
        if (!$this->callbackQueue->empty()) {
            /** @var \Revolt\EventLoop\Internal\DriverCallback $nextCb */
            $nextCb = $this->callbackQueue->top();
            $closure = $nextCb->closure;
            if (!isset($this->callbacks[$nextCb->id]) || !$nextCb->invokable) {
                return;
            }

            $span = DDTrace\start_span();
            $span->name = closureName($closure);
            $span->service = "revolt";

            $fiberStartMap[Fiber::getCurrent()] = $span;
        }
    })->bindTo($that, AbstractDriver::class)();
}, function () {

});


DDTrace\trace_method(DriverSuspension::class, "throw", function() {});
DDTrace\trace_method(DriverSuspension::class, "resume", function() {});
DDTrace\trace_method(DriverSuspension::class, "suspend", function() {});

require __DIR__ . "/vendor/autoload.php";

PHPUnit\TextUI\Command::main(false);

var_dump(\dd_trace_serialize_closed_spans());
