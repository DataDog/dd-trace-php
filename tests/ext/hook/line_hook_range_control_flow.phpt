--TEST--
Line hook ranges over loops, switch, goto and nested breaks keep begin/end strictly alternating
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function forLoop() {
    $s = 0;                     // 4  begin
    for ($i = 0; $i < 3; $i++) {
        $s += $i;
    }
    return $s;                  // 8  end
}

function whileBreak() {
    $i = 0;                     // 12 begin
    while (true) {
        $i++;
        if ($i > 2) {
            break;
        }
    }
    return $i;                  // 19 end
}

function nestedContinue() {
    $s = 0;                     // 23 begin
    foreach ([1, 2] as $a) {
        foreach ([1, 2] as $b) {
            if ($b === 1) {
                continue 2;
            }
            $s += $b;
        }
    }
    return $s;                  // 32 end
}

function withSwitch($v) {
    $r = 'none';                // 36 begin
    switch ($v) {
        case 1:
            $r = 'one';
            break;
        case 2:
            $r = 'two';
            break;
        default:
            $r = 'other';
    }
    return $r;                  // 47 end
}

function withGoto() {
    $s = 0;                     // 51 begin
    goto skip;
    $s = 99;
    skip:
    $s += 1;
    return $s;                  // 56 end
}

function breakPastEnd() {
    $s = 0;                     // 60 begin
    foreach ([1, 2, 3] as $v) {
        if ($v === 2) {
            break;              // jumps out of the range
        }
        $s += $v;               // 65 end
    }
    return $s;
}

$log = [];
$mk = function ($tag) use (&$log) {
    return function (DDTrace\LineHookData $h) use (&$log, $tag) { $log[] = $tag . $h->line; };
};

$cases = [
    'forLoop'        => [4, 8,  []],
    'whileBreak'     => [12, 19, []],
    'nestedContinue' => [23, 32, []],
    'withSwitch'     => [36, 47, [1]],
    'withGoto'       => [51, 56, []],
    'breakPastEnd'   => [60, 65, []],
];

foreach ($cases as $fn => list($start, $end, $args)) {
    $id = DDTrace\install_line_hook(__FILE__, $start, $mk('B'), $end, $mk('E'));
    $log = [];
    call_user_func_array($fn, $args);
    DDTrace\remove_hook($id);
    $s = implode(',', $log);
    $b = substr_count($s, 'B');
    $e = substr_count($s, 'E');
    $want = 'B';
    $alt = true;
    foreach ($log as $entry) {
        if ($entry[0] !== $want) { $alt = false; break; }
        $want = $want === 'B' ? 'E' : 'B';
    }
    printf("%-15s %-24s begins=%d ends=%d alternating=%s\n", $fn, $s, $b, $e, ($alt && $want === 'B') ? 'yes' : 'NO');
}

echo "Done.\n";
?>
--EXPECT--
forLoop         B4,E8                    begins=1 ends=1 alternating=yes
whileBreak      B12,E19                  begins=1 ends=1 alternating=yes
nestedContinue  B23,E32                  begins=1 ends=1 alternating=yes
withSwitch      B36,E47                  begins=1 ends=1 alternating=yes
withGoto        B51,E56                  begins=1 ends=1 alternating=yes
breakPastEnd    B60,E67                  begins=1 ends=1 alternating=yes
Done.
