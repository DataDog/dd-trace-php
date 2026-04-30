--TEST--
_convert_json function (exceed max depth)
--FILE--
<?php
function generateNestedArray($depth) {
    $array = [];
    $array[] = "before";
    $current = &$array;
    for ($i = 1; $i <= $depth; $i++) {
        $current[$i] = [];
        $current = &$current[$i];
    }
    $array[] = "after";
    return $array;
}

function t($depth, $maxDepth) {
    $j = generateNestedArray($depth);
    $data = json_encode($j);
    echo "Original (depth=$depth):\n";
    var_dump($data);


    $result = datadog\appsec\convert_json($data, $maxDepth);
    echo "After transformation (depth=$depth, max_depth=$maxDepth):\n";
    print_r($result);
}
t(1, 0);
t(4, 1);
t(4, 2);
//datadog\appsec\testing\stop_for_debugger();
t(3, 3);
--EXPECT--
Original (depth=1):
string(21) "["before",[],"after"]"
After transformation (depth=1, max_depth=0):
Array
(
)
Original (depth=4):
string(39) "["before",{"2":{"3":{"4":[]}}},"after"]"
After transformation (depth=4, max_depth=1):
Array
(
    [0] => before
    [1] => Array
        (
        )

    [4] => after
)
Original (depth=4):
string(39) "["before",{"2":{"3":{"4":[]}}},"after"]"
After transformation (depth=4, max_depth=2):
Array
(
    [0] => before
    [1] => Array
        (
            [2] => Array
                (
                )

        )

    [4] => after
)
Original (depth=3):
string(33) "["before",{"2":{"3":[]}},"after"]"
After transformation (depth=3, max_depth=3):
Array
(
    [0] => before
    [1] => Array
        (
            [2] => Array
                (
                    [3] => Array
                        (
                        )

                )

        )

    [2] => after
)
