--TEST--
Negative integer attribute keys serialize as signed decimals
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$span = \DDTrace\start_span();
$span->name = "root";
$span->attributes['nested'] = array(-1 => 'neg', 3 => 'pos');
$span->attributes[-2] = 'top';
\DDTrace\close_span();

$attributes = dd_trace_serialize_closed_spans()['chunks'][0]['spans'][0]['attributes'];
var_dump($attributes['nested']);
var_dump(isset($attributes[-2]) ? $attributes[-2] : null);
?>
--EXPECT--
array(2) {
  [-1]=>
  string(3) "neg"
  [3]=>
  string(3) "pos"
}
string(3) "top"
