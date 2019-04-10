--TEST--
dd_trace_serialize_msgpack() error conditions in strict mode
--INI--
ddtrace.strict_mode=1
display_errors=0
--FILE--
<?php
array_map(function ($data) {
    echo json_encode($data) . ' -> ';
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
--EXPECT--
true -> Expected an array

"foo" -> Expected an array

[{}] -> Serialize values must be of type array, string, int, float, bool or null

 -> Serialize values must be of type array, string, int, float, bool or null
