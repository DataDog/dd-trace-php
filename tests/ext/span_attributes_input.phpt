--TEST--
SpanData::$attributes is the primary tag input: typed values, attributes > meta > metrics, special keys lifted
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
class Point { public $x = 1; protected $hidden = 2; }

$s = \DDTrace\start_span();
$s->name = 'original.name';
$s->attributes = [
    'int' => 42,
    'float' => 1.5,
    'bool' => true,
    'string' => 'str',
    'null' => null,
    'list' => [1, 'two', false],
    'map' => ['k' => 'v', 'n' => [3]],
    'object' => new Point,
    // precedence
    'both' => 'from attributes',
    'all' => 'from attributes',
    // special keys
    'service.name' => 'attr-service',
    'resource.name' => 'attr-resource',
    'operation.name' => 'Attr.Operation',
    'span.type' => 'attr-type',
    'env' => 'attr-env',
    'version' => 'attr-version',
    'component' => 'attr-component',
    'span.kind' => 'client',
    'analytics.event' => true,
    'http.status_code' => 404,
    '_sampling_priority_v1' => 2,
    '_dd1.sr.eausr' => 1,
    'error.ignored' => true,
];
$s->meta['both'] = 'from meta';
$s->meta['all'] = 'from meta';
$s->meta['meta_only'] = 'from meta';
$s->meta['meta_metric'] = 'from meta';
$s->metrics['all'] = 3;
$s->metrics['meta_metric'] = 4;
$s->metrics['metric_only'] = 5;
$s->exception = new Exception('ignored via the attributes tag');
\DDTrace\close_span();

$span = dd_trace_serialize_closed_spans()[0];
foreach (['name', 'resource', 'service', 'type', 'env', 'version', 'component', 'span_kind'] as $field) {
    echo "$field: ", var_export(isset($span[$field]) ? $span[$field] : null, true), "\n";
}
echo "error: ", isset($span['error']) ? $span['error'] : 0, "\n";
$attrs = $span['attributes'];
foreach (['runtime-id', 'process_id', '_dd.p.tid', '_dd.p.dm', '_dd.tags.process', '_dd.agent_psr', '_dd.svc_src', 'php.compilation.total_time_ms', 'php.memory.peak_real_usage_bytes', 'php.memory.peak_usage_bytes'] as $volatile) {
    unset($attrs[$volatile]);
}
ksort($attrs);
var_dump($attrs);
?>
--EXPECT--
name: 'attr.operation'
resource: 'attr-resource'
service: 'attr-service'
type: 'attr-type'
env: 'attr-env'
version: 'attr-version'
component: 'attr-component'
span_kind: 3
error: 0
array(13) {
  ["all"]=>
  string(15) "from attributes"
  ["bool"]=>
  bool(true)
  ["both"]=>
  string(15) "from attributes"
  ["float"]=>
  float(1.5)
  ["http.status_code"]=>
  string(3) "404"
  ["int"]=>
  int(42)
  ["list"]=>
  array(3) {
    [0]=>
    int(1)
    [1]=>
    string(3) "two"
    [2]=>
    bool(false)
  }
  ["map"]=>
  array(2) {
    ["k"]=>
    string(1) "v"
    ["n"]=>
    array(1) {
      [0]=>
      int(3)
    }
  }
  ["meta_metric"]=>
  string(9) "from meta"
  ["meta_only"]=>
  string(9) "from meta"
  ["metric_only"]=>
  float(5)
  ["object"]=>
  array(1) {
    ["x"]=>
    int(1)
  }
  ["string"]=>
  string(3) "str"
}