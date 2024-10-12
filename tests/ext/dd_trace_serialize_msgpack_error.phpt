--TEST--
dd_trace_serialize_msgpack() error conditions
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
--FILE--
<?php
array_map(function ($data) {
    var_dump($data, dd_trace_serialize_msgpack($data));
    echo "\n";
}, [
    [new stdClass()],
    ['bar', stream_context_create()],
]);
?>
--EXPECTF--
[ddtrace] [warning] Serialize values must be of type array, string, int, float, bool or null
array(1) {
  [0]=>
  object(stdClass)#%d (0) {
  }
}
bool(false)

[ddtrace] [warning] Serialize values must be of type array, string, int, float, bool or null
array(2) {
  [0]=>
  string(3) "bar"
  [1]=>
  resource(%d) of type (stream-context)
}
bool(false)

[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
