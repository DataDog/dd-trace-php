--TEST--
SpanData::setTag
--FILE--
<?php

$span = \DDTrace\start_span();

$span->setTag('nil', 'none');

$span->setTag('foo', 'bar')
    ->setTag('bar', 123)
    ->setTag('empty-arr', [])
    ->setTag('int-arr', [1, 2, 3])
    ->setTag('nil', null)
    ->setTag('_dd.p.key', 'val')
    ->setTag('_dd.p.fl', 1.2)
    ->setTag('nested', ['foo' => 'bar', 'bar' => 'baz', 'alone'])
    ->setTag("string-array", ["a", "b", "c"]);

var_dump(\DDTrace\generate_distributed_tracing_headers(['tracecontext'])['tracestate']);

$child = \DDTrace\start_span();
$child->setTag('_dd.p.user', 12)
    ->setTag('num', 1.0);

var_dump(\DDTrace\generate_distributed_tracing_headers(['tracecontext'])['tracestate']);

$child->setTag('num', null);

\DDTrace\close_span();

\DDTrace\close_span();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
string(29) "dd=t.key:val;t.fl:1.2;t.dm:-0"
string(39) "dd=t.key:val;t.fl:1.2;t.dm:-0;t.user:12"
array(2) {
  [0]=>
  array(11) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["parent_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(0) ""
    ["resource"]=>
    string(0) ""
    ["service"]=>
    string(27) "DDTrace_SpanData_setTag.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(8) {
      ["foo"]=>
      string(3) "bar"
      ["empty-arr"]=>
      string(0) ""
      ["nested.foo"]=>
      string(3) "bar"
      ["nested.bar"]=>
      string(3) "baz"
      ["nested.0"]=>
      string(5) "alone"
      ["string-array.0"]=>
      string(1) "a"
      ["string-array.1"]=>
      string(1) "b"
      ["string-array.2"]=>
      string(1) "c"
    }
    ["metrics"]=>
    array(4) {
      ["bar"]=>
      float(123)
      ["int-arr.0"]=>
      float(1)
      ["int-arr.1"]=>
      float(2)
      ["int-arr.2"]=>
      float(3)
    }
  }
  [1]=>
  array(9) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["parent_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(0) ""
    ["resource"]=>
    string(0) ""
    ["service"]=>
    string(27) "DDTrace_SpanData_setTag.php"
    ["type"]=>
    string(3) "cli"
  }
}
