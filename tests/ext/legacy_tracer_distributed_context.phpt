--TEST--
DDTrace\Tracer reads the distributed context with and without an active span (DD_TRACE_GENERATE_ROOT_SPAN=0)
--SKIPIF--
<?php if (!is_file(__DIR__ . '/../../../../src/DDTrace/Tracer.php')) die('skip: needs the src/ tree of the build checkout'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--INI--
datadog.trace.sources_path={PWD}/../../../../src
datadog.autoload_no_compile=1
--FILE--
<?php

function root_context(\DDTrace\Tracer $tracer)
{
    $prop = new ReflectionProperty($tracer, 'rootContext');
    if (PHP_VERSION_ID < 80100) {
        $prop->setAccessible(true);
    }
    return $prop->getValue($tracer);
}

function check(\DDTrace\Tracer $tracer)
{
    $expected = @\DDTrace\current_context();
    $context = root_context($tracer);
    var_dump($context->getTraceId() === $expected["trace_id"]);
    var_dump($context->getSpanId() === $expected["distributed_tracing_parent_id"]);
    var_dump($context->origin === $expected["distributed_tracing_origin"]);
    echo $context->getTraceId(), " ", $context->getSpanId(), " ", $context->origin, "\n";
}

// 128-bit trace id (2^64 + 1), no active span: only the distributed context globals are set
DDTrace\set_distributed_tracing_context("18446744073709551617", "42", "synthetics");
var_dump(DDTrace\active_span());
$tracer = new \DDTrace\Tracer();
check($tracer);
var_dump(DDTrace\active_span());

// The first userland span continues the distributed trace
$scope = $tracer->startActiveSpan("op");
$span = $scope->getSpan();
var_dump($span->getTraceId() === @\DDTrace\trace_id());
echo $span->getTraceId(), " ", DDTrace\root_span()->parentId, " ", DDTrace\root_span()->origin, "\n";

// With an active span, the context is read from the root span
$tracer = new \DDTrace\Tracer();
check($tracer);

// The scope manager maps internal spans onto userland scopes with the decimal trace id
$internal = DDTrace\start_span();
$active = $tracer->getScopeManager()->getActive();
var_dump($active->getSpan()->getSpanId() === $internal->id);
var_dump($active->getSpan()->getTraceId() === @\DDTrace\trace_id());
DDTrace\close_span();
$scope->close();
?>
--EXPECT--
NULL
bool(true)
bool(true)
bool(true)
18446744073709551617 42 synthetics
NULL
bool(true)
18446744073709551617 42 synthetics
bool(true)
bool(true)
bool(true)
18446744073709551617 42 synthetics
bool(true)
bool(true)
