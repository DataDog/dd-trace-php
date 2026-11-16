--TEST--
_convert_json function
--FILE--
<?php
$j = '{"a":[],"b":{"0": 1},"c":{"d":"e"}}';


$result = datadog\appsec\convert_json($j);
echo(json_encode($result, JSON_PRETTY_PRINT)), "\n";

$result = datadog\appsec\convert_json('[1,2]');
echo(json_encode($result, JSON_PRETTY_PRINT)), "\n";

var_dump(\datadog\appsec\convert_json('{'));
--EXPECT--
{
    "a": [],
    "b": [
        1
    ],
    "c": {
        "d": "e"
    }
}
[
    1,
    2
]
array(0) {
}
