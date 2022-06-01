--TEST--
dd_trace_serialize_msgpack() properly handles span_id, trace_id and parent_id, but only outside of nested arrays
--SKIPIF--
<?php if (PHP_INT_SIZE !== 8) die('skip test for 64-bit platforms only'); ?>
--FILE--
<?php
function dd_trace_unserialize_trace_hex($message) {
    $hex = [];
    $length = strlen($message);
    for ($i = 0; $i < $length; $i++) {
        $hex[] = bin2hex($message[$i]);
    }
    return implode(' ', $hex);
}

$traces = [[
    [
        "trace_id" => "1589331357723252209",
        "parent_id" => "1589331357723252200",
        "span_id" => "1589331357723252210",
        "meta" => [
            "trace_id" => "1589331357723252209",
            "parent_id" => "1589331357723252209",
            "span_id" => "1589331357723252210",
            "test" => "1234",
        ],
    ],
]];
echo json_encode($traces) . "\n";

$encoded = dd_trace_serialize_msgpack($traces);
echo dd_trace_unserialize_trace_hex($encoded) . "\n";
?>
--EXPECT--
[[{"trace_id":"1589331357723252209","parent_id":"1589331357723252200","span_id":"1589331357723252210","meta":{"trace_id":"1589331357723252209","parent_id":"1589331357723252209","span_id":"1589331357723252210","test":"1234"}}]]
91 91 84 a8 74 72 61 63 65 5f 69 64 cf 16 0e 70 72 ff 7b d5 f1 a9 70 61 72 65 6e 74 5f 69 64 cf 16 0e 70 72 ff 7b d5 e8 a7 73 70 61 6e 5f 69 64 cf 16 0e 70 72 ff 7b d5 f2 a4 6d 65 74 61 84 a8 74 72 61 63 65 5f 69 64 b3 31 35 38 39 33 33 31 33 35 37 37 32 33 32 35 32 32 30 39 a9 70 61 72 65 6e 74 5f 69 64 b3 31 35 38 39 33 33 31 33 35 37 37 32 33 32 35 32 32 30 39 a7 73 70 61 6e 5f 69 64 b3 31 35 38 39 33 33 31 33 35 37 37 32 33 32 35 32 32 31 30 a4 74 65 73 74 a4 31 32 33 34
