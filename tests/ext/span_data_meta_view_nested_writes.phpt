--TEST--
Nested writes, increments and nested unsets through SpanData::$meta/$metrics reach $attributes
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$span = \DDTrace\start_span();
$span->name = 'nested';

$span->meta['nested']['a'] = 'x';
$span->meta['nested']['b']['c'] = 'y';
unset($span->meta['nested']['a']);
unset($span->meta['missing']['a']);
$span->meta['list'] = ['x'];
$span->meta['list'][] = 'y';
sort($span->meta['list']);
$span->metrics['counter'] = 1;
$span->metrics['counter']++;
$span->metrics['counter'] += 2;
$span->metrics['flag'] = 5;
$span->meta['flag']['now'] = 'meta';

var_dump($span->attributes['nested'], $span->attributes['list'], $span->attributes['counter'], $span->attributes['flag']);
var_dump(array_key_exists('missing', $span->attributes), $span->meta['nested']['b']['c']);
\DDTrace\close_span();

$attrs = dd_trace_serialize_closed_spans()['chunks'][0]['spans'][0]['attributes'];
foreach (['nested', 'list', 'counter', 'flag'] as $key) {
    echo $key, ' => ', json_encode($attrs[$key] ?? 'absent'), "\n";
}
?>
--EXPECT--
array(1) {
  ["b"]=>
  array(1) {
    ["c"]=>
    string(1) "y"
  }
}
array(2) {
  [0]=>
  string(1) "x"
  [1]=>
  string(1) "y"
}
float(4)
array(1) {
  ["now"]=>
  string(4) "meta"
}
bool(false)
string(1) "y"
nested => {"b":{"c":"y"}}
list => ["x","y"]
counter => 4
flag => {"now":"meta"}
