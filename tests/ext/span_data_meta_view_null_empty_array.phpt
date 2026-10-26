--TEST--
null and empty arrays written through SpanData::$meta and $metrics are stored as they are
--DESCRIPTION--
The views store them unconverted (no "null"/"" or 0.0 placeholder), so the serializer applies its
usual rule: null is skipped and an empty array is an empty list.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$keys = ['m_empty', 'm_object', 'm_null', 'm_nested', 'x_empty', 'x_null'];
$span = \DDTrace\start_span();
$span->meta['m_empty'] = [];
$span->meta['m_object'] = new stdClass;
$span->meta['m_null'] = null;
$span->meta['m_nested'] = ['l' => [1, null, []], 'n' => null];
$span->metrics['x_empty'] = [];
$span->metrics['x_null'] = null;

echo "-- stored attributes\n";
var_dump(array_intersect_key($span->attributes, array_flip($keys)));

echo "-- read-back (an array is a meta entry, null is in neither view)\n";
$meta = $span->meta;
$metrics = $span->metrics;
foreach ($keys as $key) {
    echo $key, ': meta=', array_key_exists($key, $meta) ? json_encode($meta[$key]) : 'absent',
        ' metrics=', array_key_exists($key, $metrics) ? json_encode($metrics[$key]) : 'absent', "\n";
}
\DDTrace\close_span();

echo "-- serialized\n";
$attrs = dd_trace_serialize_closed_spans()['chunks'][0]['spans'][0]['attributes'];
foreach ($keys as $key) {
    echo $key, ' => ', array_key_exists($key, $attrs) ? json_encode($attrs[$key]) : 'absent', "\n";
}
?>
--EXPECT--
-- stored attributes
array(6) {
  ["m_empty"]=>
  array(0) {
  }
  ["m_object"]=>
  array(0) {
  }
  ["m_null"]=>
  NULL
  ["m_nested"]=>
  array(2) {
    ["l"]=>
    array(3) {
      [0]=>
      string(1) "1"
      [1]=>
      NULL
      [2]=>
      array(0) {
      }
    }
    ["n"]=>
    NULL
  }
  ["x_empty"]=>
  array(0) {
  }
  ["x_null"]=>
  NULL
}
-- read-back (an array is a meta entry, null is in neither view)
m_empty: meta=[] metrics=absent
m_object: meta=[] metrics=absent
m_null: meta=absent metrics=absent
m_nested: meta={"l":["1",null,[]],"n":null} metrics=absent
x_empty: meta=[] metrics=absent
x_null: meta=absent metrics=absent
-- serialized
m_empty => []
m_object => []
m_null => absent
m_nested => {"l":["1",[]]}
x_empty => []
x_null => absent
