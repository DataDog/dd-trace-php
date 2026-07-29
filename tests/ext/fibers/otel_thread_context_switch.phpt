--TEST--
Linux OTel Thread Context follows Fiber span-stack switches
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80100) die('skip: Fibers require PHP 8.1+');
if (PHP_OS_FAMILY !== 'Linux') die('skip: Linux only');
if (!extension_loaded('ffi')) die('skip: ffi extension required');
?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

require __DIR__ . '/../includes/otel_thread_context.inc';

$context = new OtelThreadContext();
$mainRoot = DDTrace\start_span();
$mainSpanId = $context->spanId();

$fiber = new Fiber(function () use ($context, $mainSpanId) {
    echo "Inherited main context: "; var_dump($context->spanId() === $mainSpanId);

    $fiberRoot = DDTrace\start_trace_span();
    $fiberSpanId = $context->spanId();
    echo "Fiber trace selected: "; var_dump($fiberSpanId !== $mainSpanId);

    Fiber::suspend($fiberSpanId);
    echo "Fiber trace restored: "; var_dump($context->spanId() === $fiberSpanId);
    DDTrace\close_span();
});

$fiberSpanId = $fiber->start();
echo "Main context restored after suspend: "; var_dump($context->spanId() === $mainSpanId);
$fiber->resume();
echo "Main context restored after return: "; var_dump($context->spanId() === $mainSpanId);

DDTrace\close_span();
echo "Detached after main root close: "; var_dump(!$context->isActive());

?>
--EXPECT--
Inherited main context: bool(true)
Fiber trace selected: bool(true)
Main context restored after suspend: bool(true)
Fiber trace restored: bool(true)
Main context restored after return: bool(true)
Detached after main root close: bool(true)
