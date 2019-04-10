--TEST--
Basic functionality of dd_trace_serialize_msgpack()
--DESCRIPTION--
The "EXPECT" section was generated with the following tool:
https://github.com/ludocode/msgpack-tools
Example command:
$ echo '{"compact": true, "schema": 0}' | json2msgpack | hexdump
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
        "trace_id" => 1589331357723252209,
        "span_id" => 1589331357723252210,
        "name" => "test_name",
        "resource" => "test_resource",
        "service" => "test_service",
        "start" => 1518038421211969000,
        "error" => 0,
    ],
]];
echo json_encode($traces) . "\n";

$encoded = dd_trace_serialize_msgpack($traces);
echo dd_trace_unserialize_trace_hex($encoded) . "\n";
?>
--EXPECT--
[[{"trace_id":1589331357723252209,"span_id":1589331357723252210,"name":"test_name","resource":"test_resource","service":"test_service","start":1518038421211969000,"error":0}]]
91 91 87 a8 74 72 61 63 65 5f 69 64 cf 16 0e 70 72 ff 7b d5 f1 a7 73 70 61 6e 5f 69 64 cf 16 0e 70 72 ff 7b d5 f2 a4 6e 61 6d 65 a9 74 65 73 74 5f 6e 61 6d 65 a8 72 65 73 6f 75 72 63 65 ad 74 65 73 74 5f 72 65 73 6f 75 72 63 65 a7 73 65 72 76 69 63 65 ac 74 65 73 74 5f 73 65 72 76 69 63 65 a5 73 74 61 72 74 cf 15 11 27 e6 b3 bb f5 e8 a5 65 72 72 6f 72 00
