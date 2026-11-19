--TEST--
Tag reads return live views while array casts return independent snapshots
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$span = DDTrace\start_span();
$span->attributes = [];
$meta = $span->meta;
$metrics = $span->metrics;
var_dump($meta instanceof DDTrace\SpanTagsView, $metrics instanceof DDTrace\SpanTagsView);
var_dump($meta === $span->meta, $metrics === $span->metrics);

$span->attributes['text'] = 'first';
$span->attributes['number'] = 1;
var_dump($meta['text'], $metrics['number'], isset($meta['number']), isset($metrics['text']));
$meta['text'] = 'second';
$metrics['number'] = 2;
$ref = &$meta['text'];
$metaSnapshot = (array) $meta;
$metricsSnapshot = (array) $metrics;
$ref = 'third';
$metrics['number'] = 3;
unset($ref);
var_dump($metaSnapshot['text'], $metricsSnapshot['number']);
var_dump(((array) $meta)['text'], ((array) $metrics)['number']);

$metaSnapshot['text'] = 'snapshot-only';
$metricsSnapshot['number'] = 99;
var_dump($span->attributes['text'], $span->attributes['number']);
$merged = array_merge((array) $meta, ['extra' => 'value']);
var_dump($merged['text'], $merged['extra']);
echo json_encode($metrics), "\n";
var_dump(iterator_to_array($metrics));

// A snapshot cached by an older engine's cast handler must remain safe for cyclic GC.
class CyclicTag {
    public static $destroyed = 0;
    public $view;
    public function __destruct() { ++self::$destroyed; }
}
$cycle = new CyclicTag;
$cycle->view = $meta;
$span->attributes['cycle'] = $cycle;
$snapshot = (array) $meta;
unset($snapshot, $cycle, $span->attributes['cycle'], $meta, $metrics);
DDTrace\close_span();
unset($span);
dd_trace_serialize_closed_spans();
gc_collect_cycles();
var_dump(CyclicTag::$destroyed);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
string(5) "first"
int(1)
bool(false)
bool(false)
string(6) "second"
float(2)
string(5) "third"
float(3)
string(5) "third"
float(3)
string(5) "third"
string(5) "value"
{"number":3}
array(1) {
  ["number"]=>
  float(3)
}
int(1)
