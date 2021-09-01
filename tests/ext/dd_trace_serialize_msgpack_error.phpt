--TEST--
dd_trace_serialize_msgpack() error conditions
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_TRACE_DEBUG=1
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
Expected argument to dd_trace_serialize_msgpack() to be an array
bool(true)
bool(false)

Expected argument to dd_trace_serialize_msgpack() to be an array
string(3) "foo"
bool(false)

Serialize values must be of type array, string, int, float, bool or null
array(1) {
  [0]=>
  object(stdClass)#%d (0) {
  }
}
bool(false)

Serialize values must be of type array, string, int, float, bool or null
array(2) {
  [0]=>
  string(3) "bar"
  [1]=>
  resource(%d) of type (stream-context)
}
bool(false)

Successfully triggered flush with trace of size 1
