--TEST--
Linux OTel Thread Context follows span lifecycle and root metadata
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
DD_SERVICE=configured-service
DD_ENV=configured-env
DD_VERSION=configured-version
DD_TRACE_SAMPLE_RATE=1
--FILE--
<?php

require __DIR__ . '/includes/otel_thread_context.inc';

$context = new OtelThreadContext();
echo "Initially detached: "; var_dump(!$context->isActive());

$root = DDTrace\start_span();
echo "Root attached: "; var_dump($context->isActive());
echo "Trace ID: "; var_dump($context->traceId() === $root->traceId);

$attributes = $context->attributes();
echo "Configured service: "; var_dump($attributes[1] === 'configured-service');
echo "Configured environment: "; var_dump($attributes[2] === 'configured-env');
echo "Configured version: "; var_dump($attributes[3] === 'configured-version');
echo "Local root ID: "; var_dump(strlen($attributes[0]) === 16);
echo "OS thread ID: "; var_dump(ctype_digit($attributes[4]));

$root->service = 'root-service';
$root->env = 'root-env';
$root->version = 'root-version';
$attributes = $context->attributes();
echo "Root service override: "; var_dump($attributes[1] === 'root-service');
echo "Root environment override: "; var_dump($attributes[2] === 'root-env');
echo "Root version override: "; var_dump($attributes[3] === 'root-version');

$root->samplingPriority = DD_TRACE_PRIORITY_SAMPLING_UNKNOWN;
echo "Undecided trace flags: "; var_dump($context->traceFlags());
DDTrace\get_priority_sampling();
echo "Lazy sampling decision flags: "; var_dump($context->traceFlags());
$root->samplingPriority = DD_TRACE_PRIORITY_SAMPLING_USER_REJECT;
echo "Rejected trace flags: "; var_dump($context->traceFlags());
$root->samplingPriority = DD_TRACE_PRIORITY_SAMPLING_USER_KEEP;
echo "Kept trace flags: "; var_dump($context->traceFlags());

$rootSpanId = $context->spanId();
$child = DDTrace\start_span();
echo "Child span selected: "; var_dump($context->spanId() !== $rootSpanId);
DDTrace\close_span();
echo "Root span restored: "; var_dump($context->spanId() === $rootSpanId);
DDTrace\close_span();
echo "Detached after root close: "; var_dump(!$context->isActive());

?>
--EXPECT--
Initially detached: bool(true)
Root attached: bool(true)
Trace ID: bool(true)
Configured service: bool(true)
Configured environment: bool(true)
Configured version: bool(true)
Local root ID: bool(true)
OS thread ID: bool(true)
Root service override: bool(true)
Root environment override: bool(true)
Root version override: bool(true)
Undecided trace flags: int(2)
Lazy sampling decision flags: int(3)
Rejected trace flags: int(2)
Kept trace flags: int(3)
Child span selected: bool(true)
Root span restored: bool(true)
Detached after root close: bool(true)
