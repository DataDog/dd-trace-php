--TEST--
SpanData::$attributes is a raw typed store; only $meta/$metrics writes map special keys onto span properties
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';
class Point { public $x = 1; protected $hidden = 2; }

$special = [
    'service.name' => 'special-service',
    'resource.name' => 'special-resource',
    'operation.name' => 'Special.Operation',
    'span.type' => 'special-type',
    'env' => 'special-env',
    'version' => 'special-version',
    'component' => 'special-component',
    'span.kind' => 'client',
    'error.ignored' => true,
];

// Attributes keep every key, typed, special keys included; no property is set from them
$raw = \DDTrace\start_span();
$raw->name = 'raw.name';
$raw->attributes = $special + [
    'int' => 42,
    'float' => 1.5,
    'bool' => true,
    'string' => 'str',
    'null' => null,
    'list' => [1, 'two', false],
    'map' => ['k' => 'v', 'n' => [3]],
    'object' => new Point,
    'http.status_code' => 404,
    '_sampling_priority_v1' => 2,
    '_dd1.sr.eausr' => 1,
    // overwritten below through $meta / $metrics
    'both' => 'from attributes',
    'all' => 'from attributes',
];
$raw->meta['both'] = 'from meta';
$raw->meta['all'] = 'from meta';
$raw->meta['meta_only'] = 'from meta';
$raw->meta['meta_metric'] = 'from meta';
$raw->metrics['all'] = 3;
$raw->metrics['meta_metric'] = 4;
$raw->metrics['metric_only'] = 5;
$raw->exception = new Exception('not ignored by the error.ignored attribute');
\DDTrace\close_span();

// $meta writes of special keys set the properties and read back through $meta
$viaMeta = \DDTrace\start_span();
$viaMeta->name = 'meta.name';
foreach ($special as $key => $value) {
    $viaMeta->meta[$key] = $value;
}
echo "meta read-back: component=", var_export($viaMeta->meta['component'], true), " span.kind=", var_export($viaMeta->meta['span.kind'], true), "\n";
$viaMeta->exception = new Exception('ignored via $meta');
\DDTrace\close_span();

$volatile = array_flip(['runtime-id', 'process_id', '_dd.p.tid', '_dd.p.dm', '_dd.tags.process', '_dd.sdk.otlp_export', '_dd.agent_psr', '_dd.svc_src', '_dd.base_service', 'php.compilation.total_time_ms', 'php.memory.peak_real_usage_bytes', 'php.memory.peak_usage_bytes', 'error.stack']);
foreach (array_reverse(dd_clean_spans()) as $span) {
    echo "== {$span['name']}\n";
    foreach (['resource', 'service', 'type', 'env', 'version', 'component', 'span_kind'] as $field) {
        echo "$field: ", var_export(isset($span[$field]) ? $span[$field] : null, true), "\n";
    }
    echo "error: ", isset($span['error']) ? $span['error'] : 0, "\n";
    $attrs = array_diff_key($span['attributes'], $volatile);
    ksort($attrs);
    var_dump($attrs);
}
?>
--EXPECTF--
meta read-back: component='special-component' span.kind='client'
== raw.name
resource: 'raw.name'
service: 'span_attributes_input.php'
type: 'cli'
env: NULL
version: NULL
component: NULL
span_kind: NULL
error: 1
array(26) {
  ["_dd1.sr.eausr"]=>
  int(1)
  ["_sampling_priority_v1"]=>
  int(2)
  ["all"]=>
  float(3)
  ["bool"]=>
  bool(true)
  ["both"]=>
  string(9) "from meta"
  ["component"]=>
  string(17) "special-component"
  ["env"]=>
  string(11) "special-env"
  ["error.ignored"]=>
  bool(true)
  ["error.message"]=>
  string(%d) "Uncaught Exception: not ignored by the error.ignored attribute in %s"
  ["error.type"]=>
  string(9) "Exception"
  ["float"]=>
  float(1.5)
  ["http.status_code"]=>
  int(404)
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
  float(4)
  ["meta_only"]=>
  string(9) "from meta"
  ["metric_only"]=>
  float(5)
  ["object"]=>
  array(1) {
    ["x"]=>
    int(1)
  }
  ["operation.name"]=>
  string(17) "Special.Operation"
  ["resource.name"]=>
  string(16) "special-resource"
  ["service.name"]=>
  string(15) "special-service"
  ["span.kind"]=>
  string(6) "client"
  ["span.type"]=>
  string(12) "special-type"
  ["string"]=>
  string(3) "str"
  ["version"]=>
  string(15) "special-version"
}
== special.operation
resource: 'special-resource'
service: 'special-service'
type: 'special-type'
env: 'special-env'
version: 'special-version'
component: 'special-component'
span_kind: 3
error: 0
array(0) {
}