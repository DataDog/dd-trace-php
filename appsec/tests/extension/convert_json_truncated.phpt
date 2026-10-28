--TEST--
convert_json function - truncated JSON parsing
--FILE--
<?php

function t($name, $jsonStr) {
    static $count = 1;
    echo "Test $count: $name:", "\n";
    $count++;

    echo "Original:\n";
    echo $jsonStr, "\n";
    print_r(\datadog\appsec\convert_json($jsonStr));
}

t("Truncated object", '{"key": "value", "number": 42');

t("Truncated array", '[1, 2, 3, 4');

t("Nested truncated object", '{"outer": {"inner": {"deep": "value"}, "other": 123');

t("String truncation", '{"message": "This is a long messa');

t("Number truncation", '{"pi": 3.14159');

t("Mixed array truncation", '["string", 123, true, null, {"nested": "obj"}');

t("Deep nesting truncation", '{"a": {"b": {"c": {"d": "value"');

t("Array of objects truncation", '[{"id": 1, "name": "first"}, {"id": 2, "name": "sec');

t("Truncated after key", '{"key1": "value1", "key2":');

t("Truncated with trailing comma", '{"a": 1, "b": 2,');
--EXPECTF--
Test 1: Truncated object:
Original:
{"key": "value", "number": 42
Array
(
    [key] => value
    [number] => 42
)
Test 2: Truncated array:
Original:
[1, 2, 3, 4
Array
(
    [0] => 1
    [1] => 2
    [2] => 3
    [3] => 4
)
Test 3: Nested truncated object:
Original:
{"outer": {"inner": {"deep": "value"}, "other": 123
Array
(
    [outer] => Array
        (
            [inner] => Array
                (
                    [deep] => value
                )

            [other] => 123
        )

)
Test 4: String truncation:
Original:
{"message": "This is a long messa
Array
(
)
Test 5: Number truncation:
Original:
{"pi": 3.14159
Array
(
    [pi] => 3.14159
)
Test 6: Mixed array truncation:
Original:
["string", 123, true, null, {"nested": "obj"}
Array
(
    [0] => string
    [1] => 123
    [2] => 1
    [3] => 
    [4] => Array
        (
            [nested] => obj
        )

)
Test 7: Deep nesting truncation:
Original:
{"a": {"b": {"c": {"d": "value"
Array
(
    [a] => Array
        (
            [b] => Array
                (
                    [c] => Array
                        (
                            [d] => value
                        )

                )

        )

)
Test 8: Array of objects truncation:
Original:
[{"id": 1, "name": "first"}, {"id": 2, "name": "sec
Array
(
    [0] => Array
        (
            [id] => 1
            [name] => first
        )

    [1] => Array
        (
            [id] => 2
        )

)
Test 9: Truncated after key:
Original:
{"key1": "value1", "key2":
Array
(
    [key1] => value1
)
Test 10: Truncated with trailing comma:
Original:
{"a": 1, "b": 2,
Array
(
    [a] => 1
    [b] => 2
)
