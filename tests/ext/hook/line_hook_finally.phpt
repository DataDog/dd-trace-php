--TEST--
Line hook ranges close promptly across finally, including on its ZEND_FAST_RET return edge
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function ret_from_try() {
    try {
        $a = 1;
        return 'r';
    } finally {
        $b = 2;
    }
}

function range_in_finally($throw) {
    try {
        if ($throw) { throw new RuntimeException('x'); }
    } catch (RuntimeException $e) {
    } finally {
        $a = 1;
        $b = 2;
    }
    return 'f';
}

function break_out_of_try() {
    $n = 0;
    foreach ([1, 2, 3] as $v) {
        try {
            $n++;
            if ($v === 2) { break; }
            $n += 10;
        } finally {
            $n += 100;
        }
    }
    return $n;
}

function nested_finally() {
    try {
        try {
            $a = 1;
            return 'n';
        } finally {
            $b = 2;
        }
    } finally {
        $c = 3;
    }
}

$log = [];
$mk = function ($tag) use (&$log) {
    return function (DDTrace\LineHookData $h) use (&$log, $tag) { $log[] = $tag . $h->line; };
};

// Every begin must be followed by exactly one end before the next begin, whatever route control flow takes.
function alternates(array $log) {
    $open = false;
    foreach ($log as $e) {
        if ($e[0] === 'B') {
            if ($open) { return false; }
            $open = true;
        } else {
            if (!$open) { return false; }
            $open = false;
        }
    }
    return !$open;
}

function run($name, $lines, $mk, callable $body) {
    global $log;
    $log = [];
    $ids = [DDTrace\install_line_hook(__FILE__, $lines[0], $mk('B'), $lines[1], $mk('E'))];
    $body();
    foreach ($ids as $id) { DDTrace\remove_hook($id); }
    printf("%-18s %-24s %s\n", $name, implode(',', $log), alternates($log) ? 'ok' : 'INTERLEAVED');
}

// The end line each range reports is where control actually landed, which for a range leaving a try is inside the finally: ZEND_FAST_CALL's target is static, so the walk arms it as an exit edge.
// ZEND_FAST_RET's target is computed at run time, but the set of possible values is not -- it is the instruction after each FAST_CALL that reaches this finally -- so a range *inside* a finally is closed by an armed exit rather than by the frame guard.
// That instruction is usually a compiler-inserted jump carrying the *construct's* line, so the reported line is the one it lands on (20 here, the statement after the try), not the jump's own.

// try body, closed by the finally that runs on the way out
run('ret_from_try', [5, 6], $mk, function () { ret_from_try(); });
// wholly inside the finally block, reached both normally and after a caught throw
run('finally_plain', [17, 18], $mk, function () { range_in_finally(false); });
run('finally_throw', [17, 18], $mk, function () { range_in_finally(true); });
// break out of a try with a finally, from inside the range
run('break_out', [27, 29], $mk, function () { break_out_of_try(); });
// range in the inner try of two nested finallys
run('nested', [40, 41], $mk, function () { nested_finally(); });
// range spanning the try into the finally
run('span_into_finally', [5, 8], $mk, function () { ret_from_try(); });

echo "Done.\n";
?>
--EXPECT--
ret_from_try       B5,E8                    ok
finally_plain      B17,E20                  ok
finally_throw      B17,E20                  ok
break_out          B27,E30,B27,E31          ok
nested             B40,E43                  ok
span_into_finally  B5,E8                    ok
Done.
