--TEST--
Indirect writes through the legacy span wrapper and retained tag views reach the underlying span
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
$root = DDTrace\start_span();
$tracer = new DDTrace\Tracer();
$scope = $tracer->startActiveSpan('legacy.child');
$span = $scope->getSpan();
$internal = DDTrace\active_span();
$tags = $span->tags;
$metrics = $span->metrics;
var_dump($tags instanceof DDTrace\SpanTagsView, $metrics instanceof DDTrace\SpanTagsView);

$span->tags['tag'] = 'written';
$span->metrics['metric'] = 2;
$span->metrics['metric']++;
$tags['retained'] = 'live';
$metrics['retained_metric'] = 4;
$span->tags['nested']['leaf'] = 'nested';
$span->tags['reference'] = 'before';
$ref = &$span->tags['reference'];
$ref = 'after';
unset($ref);
foreach (['tag', 'metric', 'retained', 'retained_metric', 'nested', 'reference'] as $key) {
    echo $key, ': ', json_encode($internal->attributes[$key] ?? null), "\n";
}
var_dump($span->getTag('tag'), $span->hasTag('tag'), $span->hasTag('missing'));
var_dump(is_array($span->getAllTags()), is_array($span->getMetrics()));

$span->tags += ['tag' => 'ignored', 'union' => 'tags'];
$span->metrics += ['metric' => 99, 'union_metric' => '6'];
var_dump($tags['tag'], $tags['union'], $metrics['metric'], $metrics['union_metric']);

unset($span->tags['tag'], $span->metrics['metric']);
var_dump(isset($internal->attributes['tag']), isset($internal->attributes['metric']));
$span->tags = ['replacement' => 'new'];
$span->metrics = ['replacement_metric' => 5];
var_dump($tags['replacement'], $metrics['replacement_metric']);
var_dump(isset($tags['retained']), isset($metrics['retained_metric']));

$scope->close();
DDTrace\close_span();
?>
--EXPECT--
bool(true)
bool(true)
tag: "written"
metric: 3
retained: "live"
retained_metric: 4
nested: {"leaf":"nested"}
reference: "after"
string(7) "written"
bool(true)
bool(false)
bool(true)
bool(true)
string(7) "written"
string(4) "tags"
float(3)
float(6)
bool(false)
bool(false)
string(3) "new"
float(5)
bool(false)
bool(false)
