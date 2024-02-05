--TEST--
_convert_json function (exceed max depth)
--FILE--
<?php
function generateNestedArray($depth) {
    $array = [];
    $current = &$array;
    for ($i = 1; $i <= $depth; $i++) {
        $current[$i] = [];
        $current = &$current[$i];
    }
    return $array;
}

function t($depth) {
    $j = generateNestedArray($depth);
    $data = json_encode($j);
    echo "Original ($depth):\n";
    var_dump($data);


    $result = datadog\appsec\testing\convert_json($data);
    echo "After transformation ($depth):\n";
    echo(json_encode($result)), "\n";
}
t(28);
t(29);
--EXPECT--
Original (28):
string(189) "{"1":{"2":{"3":{"4":{"5":{"6":{"7":{"8":{"9":{"10":{"11":{"12":{"13":{"14":{"15":{"16":{"17":{"18":{"19":{"20":{"21":{"22":{"23":{"24":{"25":{"26":{"27":{"28":[]}}}}}}}}}}}}}}}}}}}}}}}}}}}}"
After transformation (28):
{"1":{"2":{"3":{"4":{"5":{"6":{"7":{"8":{"9":{"10":{"11":{"12":{"13":{"14":{"15":{"16":{"17":{"18":{"19":{"20":{"21":{"22":{"23":{"24":{"25":{"26":{"27":{"28":[]}}}}}}}}}}}}}}}}}}}}}}}}}}}}
Original (29):
string(196) "{"1":{"2":{"3":{"4":{"5":{"6":{"7":{"8":{"9":{"10":{"11":{"12":{"13":{"14":{"15":{"16":{"17":{"18":{"19":{"20":{"21":{"22":{"23":{"24":{"25":{"26":{"27":{"28":{"29":[]}}}}}}}}}}}}}}}}}}}}}}}}}}}}}"
After transformation (29):
null
