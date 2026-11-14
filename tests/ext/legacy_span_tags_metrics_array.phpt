--TEST--
Legacy DDTrace\Data\Span $tags and $metrics support explicit array casts
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
$root = \DDTrace\start_span();
$tracer = new \DDTrace\Tracer();
$scope = $tracer->startActiveSpan('legacy.child');
$span = $scope->getSpan();
$span->setTag('foo', 'bar');
$span->setMetric('m', 2);

var_dump($span instanceof \DDTrace\Data\Span, is_array((array) $span->tags), is_array((array) $span->metrics));
$merged = array_merge((array) $span->tags, array('extra' => 'x'));
var_dump($merged['foo'], $merged['extra'], in_array('foo', array_keys((array) $span->tags), true));
echo json_encode($span->metrics), "\n";
var_dump(empty($span->metrics), isset($span->tags));

$scope->close();
\DDTrace\close_span();
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
string(3) "bar"
string(1) "x"
bool(true)
{"m":2}
bool(false)
bool(true)
