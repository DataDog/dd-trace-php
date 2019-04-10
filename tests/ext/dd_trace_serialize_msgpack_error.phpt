--TEST--
dd_trace_serialize_msgpack() error conditions
--INI--
display_errors=0
--FILE--
<?php
array_map(function ($data) {
    echo json_encode($data) . ' -> ';
    var_dump(dd_trace_serialize_msgpack($data));
    echo "\n";
}, [
    true,
    'foo',
    [new stdClass()],
    ['bar', stream_context_create()],
]);
?>
--EXPECT--
true -> bool(false)

"foo" -> bool(false)

[{}] -> bool(false)

 -> bool(false)
