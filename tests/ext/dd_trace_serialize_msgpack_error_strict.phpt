--TEST--
dd_trace_serialize_msgpack() error conditions in strict mode
--INI--
ddtrace.strict_mode=1
--FILE--
<?php
array_map(function ($data) {
    var_dump($data);
    try {
        dd_trace_serialize_msgpack($data);
    } catch (\InvalidArgumentException $e) {
        echo $e->getMessage();
    }
    echo "\n\n";
}, [
    true,
    'foo',
    [new stdClass()],
    ['bar', stream_context_create()],
]);
?>
--EXPECTF--
bool(true)
Expected an array

string(3) "foo"
Expected an array

array(1) {
  [0]=>
  object(stdClass)#%d (0) {
  }
}
Serialize values must be of type array, string, int, float, bool or null

array(2) {
  [0]=>
  string(3) "bar"
  [1]=>
  resource(%d) of type (stream-context)
}
Serialize values must be of type array, string, int, float, bool or null
