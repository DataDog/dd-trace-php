--TEST--
Whatever kind of opline follows a range's end line, the range still closes exactly once
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

class Sp { public static $p = 0; }

function afterDimAssign() {
    $x = [];                        // 6  begin
    $y = 1;                         // 7  end
    $x['a']['b'] = $y;              // 8  next line starts with a dim write
    return $x;
}

function afterAppend() {
    $x = [];                        // 13 begin
    $y = 1;                         // 14 end
    $x[] = $y;                      // 15
    return $x;
}

function afterStaticProp() {
    $y = 1;                         // 20 begin
    $z = 2;                         // 21 end
    Sp::$p = $y + $z;               // 22
    return Sp::$p;
}

function afterCompoundDim() {
    $x = ['a' => 1];                // 27 begin
    $y = 1;                         // 28 end
    $x['a'] += $y;                  // 29
    return $x['a'];
}

function afterList() {
    $y = 1;                         // 34 begin
    $z = [1, 2];                    // 35 end
    list($a, $b) = $z;              // 36
    return $a + $b + $y;
}

function afterIf($v) {
    $y = 1;                         // 41 begin
    $z = 2;                         // 42 end
    if ($v === $z) {                // 43 smart-branch comparison
        return 'eq';
    }
    return 'ne' . $y;
}

function afterForeach() {
    $y = 1;                         // 50 begin
    $s = 0;                         // 51 end
    foreach ([1, 2] as $v) {        // 52
        $s += $v;
    }
    return $s + $y;
}

function afterTry() {
    $y = 1;                         // 59 begin
    $z = 2;                         // 60 end
    try {                           // 61
        return $y + $z;
    } finally {
        $q = 3;
    }
}

function afterCloseBrace($v) {
    if ($v) {
        $y = 1;                     // 70 begin
        $z = 2;                     // 71 end
    }                               // 72 nothing but a brace
    return 'x';
}

function afterHeredoc() {
    $y = 1;                         // 77 begin
    $z = 2;                         // 78 end
    $s = <<<TXT
v=$y$z
TXT;
    return $s;
}

$log = [];
$mk = function ($tag) use (&$log) {
    return function (DDTrace\LineHookData $h) use (&$log, $tag) { $log[] = $tag . $h->line; };
};

$cases = [
    'afterDimAssign'   => [6, 7, []],
    'afterAppend'      => [13, 14, []],
    'afterStaticProp'  => [20, 21, []],
    'afterCompoundDim' => [27, 28, []],
    'afterList'        => [34, 35, []],
    'afterIf'          => [41, 42, [2]],
    'afterForeach'     => [50, 51, []],
    'afterTry'         => [59, 60, []],
    'afterCloseBrace'  => [70, 71, [true]],
    'afterHeredoc'     => [77, 78, []],
];

foreach ($cases as $fn => list($start, $end, $args)) {
    $id = DDTrace\install_line_hook(__FILE__, $start, $mk('B'), $end, $mk('E'));
    $log = [];
    call_user_func_array($fn, $args);
    DDTrace\remove_hook($id);
    $s = implode(',', $log);
    printf("%-17s %-12s begins=%d ends=%d\n", $fn, $s, substr_count($s, 'B'), substr_count($s, 'E'));
}

echo "Done.\n";
?>
--EXPECT--
afterDimAssign    B6,E8        begins=1 ends=1
afterAppend       B13,E15      begins=1 ends=1
afterStaticProp   B20,E22      begins=1 ends=1
afterCompoundDim  B27,E29      begins=1 ends=1
afterList         B34,E36      begins=1 ends=1
afterIf           B41,E43      begins=1 ends=1
afterForeach      B50,E52      begins=1 ends=1
afterTry          B59,E62      begins=1 ends=1
afterCloseBrace   B70,E73      begins=1 ends=1
afterHeredoc      B77,E80      begins=1 ends=1
Done.
