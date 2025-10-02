--TEST--
convert_json function - object with string keys preservation
--FILE--
<?php

function t($name, $jsonStr) {
    static $count = 1;
    echo "Test $count: $name:", "\n";
    $count++;

    echo "Input: $jsonStr\n";
    $result = \datadog\appsec\convert_json($jsonStr);
    echo "Result type: ", gettype($result), "\n";
    var_dump($result);
    echo "\n";
}

t("Simple object with string key", '{"a":"b"}');

t("Object with multiple string keys", '{"a":"b","c":"d"}');

t("Nested object with string keys", '{"outer":{"inner":"value"}}');

t("Object with mixed string keys", '{"name":"John","age":"30","city":"NYC"}');

t("Object with numeric string values", '{"key":"123"}');

t("Object with empty string value", '{"a":""}');

t("Single key-value with space", '{"key": "value with spaces"}');

t("UTF-8 string keys and values", '{"名前":"太郎"}');

?>
--EXPECT--
Test 1: Simple object with string key:
Input: {"a":"b"}
Result type: array
array(1) {
  ["a"]=>
  string(1) "b"
}

Test 2: Object with multiple string keys:
Input: {"a":"b","c":"d"}
Result type: array
array(2) {
  ["a"]=>
  string(1) "b"
  ["c"]=>
  string(1) "d"
}

Test 3: Nested object with string keys:
Input: {"outer":{"inner":"value"}}
Result type: array
array(1) {
  ["outer"]=>
  array(1) {
    ["inner"]=>
    string(5) "value"
  }
}

Test 4: Object with mixed string keys:
Input: {"name":"John","age":"30","city":"NYC"}
Result type: array
array(3) {
  ["name"]=>
  string(4) "John"
  ["age"]=>
  string(2) "30"
  ["city"]=>
  string(3) "NYC"
}

Test 5: Object with numeric string values:
Input: {"key":"123"}
Result type: array
array(1) {
  ["key"]=>
  string(3) "123"
}

Test 6: Object with empty string value:
Input: {"a":""}
Result type: array
array(1) {
  ["a"]=>
  string(0) ""
}

Test 7: Single key-value with space:
Input: {"key": "value with spaces"}
Result type: array
array(1) {
  ["key"]=>
  string(17) "value with spaces"
}

Test 8: UTF-8 string keys and values:
Input: {"名前":"太郎"}
Result type: array
array(1) {
  ["名前"]=>
  string(6) "太郎"
}

