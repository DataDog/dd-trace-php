--TEST--
convert_json function - special values
--FILE--
<?php

function t($name, $jsonStr) {
    static $count = 1;
    echo "Test $count: $name:", "\n";
    $count++;

    echo "Original:\n";
    echo $jsonStr, "\n";
    echo "Result:\n";
    var_dump(\datadog\appsec\convert_json($jsonStr));
    echo "\n";
}

t("Boolean values", '{"isTrue": true, "isFalse": false}');

t("Null value", 'null');

t("Numbers", '{"int": 42, "float": 3.14, "negative": -10, "zero": 0}');

t("Empty structures", '{"empty_obj": {}, "empty_arr": []}');

t("Escaped characters", '{"unicode": "\\u0041\\u0042", "simple": "test"}');

t("Trailing comma", '{"a": 1, "b": 2,}');

t("Comments", '{"a": 1, /* comment */ "b": 2}');

t("NaN and Infinity", '{"nan": NaN, "inf": Infinity, "negInf": -Infinity}');

t("Empty input", '');

t("Single string value", '"just a string"');

t("Single number", '42');

t("Large integer", '{"bignum": 9223372036854775807}');
--EXPECT--
Test 1: Boolean values:
Original:
{"isTrue": true, "isFalse": false}
Result:
array(2) {
  ["isTrue"]=>
  bool(true)
  ["isFalse"]=>
  bool(false)
}

Test 2: Null value:
Original:
null
Result:
NULL

Test 3: Numbers:
Original:
{"int": 42, "float": 3.14, "negative": -10, "zero": 0}
Result:
array(4) {
  ["int"]=>
  int(42)
  ["float"]=>
  float(3.14)
  ["negative"]=>
  int(-10)
  ["zero"]=>
  int(0)
}

Test 4: Empty structures:
Original:
{"empty_obj": {}, "empty_arr": []}
Result:
array(2) {
  ["empty_obj"]=>
  array(0) {
  }
  ["empty_arr"]=>
  array(0) {
  }
}

Test 5: Escaped characters:
Original:
{"unicode": "\u0041\u0042", "simple": "test"}
Result:
array(2) {
  ["unicode"]=>
  string(2) "AB"
  ["simple"]=>
  string(4) "test"
}

Test 6: Trailing comma:
Original:
{"a": 1, "b": 2,}
Result:
array(2) {
  ["a"]=>
  int(1)
  ["b"]=>
  int(2)
}

Test 7: Comments:
Original:
{"a": 1, /* comment */ "b": 2}
Result:
array(2) {
  ["a"]=>
  int(1)
  ["b"]=>
  int(2)
}

Test 8: NaN and Infinity:
Original:
{"nan": NaN, "inf": Infinity, "negInf": -Infinity}
Result:
array(3) {
  ["nan"]=>
  float(NAN)
  ["inf"]=>
  float(INF)
  ["negInf"]=>
  float(-INF)
}

Test 9: Empty input:
Original:

Result:
NULL

Test 10: Single string value:
Original:
"just a string"
Result:
string(13) "just a string"

Test 11: Single number:
Original:
42
Result:
int(42)

Test 12: Large integer:
Original:
{"bignum": 9223372036854775807}
Result:
array(1) {
  ["bignum"]=>
  int(9223372036854775807)
}
