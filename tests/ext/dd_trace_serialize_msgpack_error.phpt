--TEST--
dd_trace_serialize_msgpack() error conditions
--FILE--
<?php
array_map(function ($data) {
    var_dump($data, dd_trace_serialize_msgpack($data));
    echo "\n";
}, [
    true,
    'foo',
    [new stdClass()],
    ['bar', stream_context_create()],
]);
?>
--EXPECTF--
bool(true)
bool(false)

string(3) "foo"
bool(false)

array(1) {
  [0]=>
  object(stdClass)#%d (0) {
  }
}
bool(false)

array(2) {
  [0]=>
  string(3) "bar"
  [1]=>
  resource(%d) of type (stream-context)
}
bool(false)
