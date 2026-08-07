--TEST--
Linux OTel Thread Context follows manual span-stack switches
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70400) die('skip: FFI requires PHP 7.4+');
if (PHP_OS_FAMILY !== 'Linux') die('skip: Linux only');
if (!extension_loaded('ffi')) die('skip: ffi extension required');
?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

require __DIR__ . '/includes/otel_thread_context.inc';

$context = new OtelThreadContext();

$mainRoot = DDTrace\start_span();
$mainRoot->service = 'main-service';
$mainRoot->env = 'main-env';
$mainRoot->version = 'main-version';
$mainSpanId = $context->spanId();

$otherRoot = DDTrace\start_trace_span();
$otherRoot->service = 'other-service';
$otherSpanId = $context->spanId();
echo "Other root selected: "; var_dump(
    $otherSpanId !== $mainSpanId
    && $context->attributes()[1] === 'main-service'
);

$mainRoot->service = 'updated-main-service';
$mainRoot->env = 'updated-main-env';
$mainRoot->version = 'updated-main-version';
echo "Active nested root metadata updated: "; var_dump(
    array_slice($context->attributes(), 1, 3) === [
        'updated-main-service',
        'updated-main-env',
        'updated-main-version',
    ]
);

DDTrace\switch_stack($mainRoot);
echo "Main root selected: "; var_dump(
    $context->spanId() === $mainSpanId
    && array_slice($context->attributes(), 1, 3) === [
        'updated-main-service',
        'updated-main-env',
        'updated-main-version',
    ]
);

DDTrace\switch_stack($otherRoot);
echo "Other root restored: "; var_dump(
    $context->spanId() === $otherSpanId
    && array_slice($context->attributes(), 1, 3) === [
        'updated-main-service',
        'updated-main-env',
        'updated-main-version',
    ]
);

DDTrace\close_span();
echo "Main root restored after close: "; var_dump($context->spanId() === $mainSpanId);
DDTrace\close_span();
echo "Detached after both roots close: "; var_dump(!$context->isActive());

?>
--EXPECT--
Other root selected: bool(true)
Active nested root metadata updated: bool(true)
Main root selected: bool(true)
Other root restored: bool(true)
Main root restored after close: bool(true)
Detached after both roots close: bool(true)
