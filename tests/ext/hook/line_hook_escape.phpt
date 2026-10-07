--TEST--
Line hook ranges close at the jump that leaves them, and partially overlapping ranges are rejected
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function escapes_by_break() {
    $seen = [];
    foreach ([1, 2, 3] as $v) {
        $seen[] = $v;   // 6  begin
        if ($v === 2) {
            break;      // 8  -- jumps past the end line
        }
        $seen[] = -$v;  // 10 end
    }
    return count($seen);
}

function loops() {
    $t = 0;
    for ($i = 0; $i < 3; $i++) {    // 17  begin sits on the for header
        $t += $i;                   // 18  end
    }
    return $t;
}

$log = [];
$mk = function ($tag) use (&$log) { return function () use (&$log, $tag) { $log[] = $tag; }; };

$a = DDTrace\install_line_hook(__FILE__, 6, $mk('b'), 10, $mk('e'));
escapes_by_break();
echo "break: ", implode(',', $log), "\n";
DDTrace\remove_hook($a);

// A `for` header is reached once more than the body runs, so re-entering an open range must close it first: every begin is matched by exactly one end, and they strictly alternate.
$log = [];
$b = DDTrace\install_line_hook(__FILE__, 17, $mk('B'), 18, $mk('E'));
loops();
$seq = implode('', $log);
var_dump($seq === str_repeat('BE', strlen($seq) / 2));
DDTrace\remove_hook($b);

// Disjoint and properly nested are fine; partial overlap is not.
$x = DDTrace\install_line_hook(__FILE__, 3, null, 5, null);
$y = DDTrace\install_line_hook(__FILE__, 10, null, 12, null);
$z = DDTrace\install_line_hook(__FILE__, 3, null, 12, null);
var_dump($x < 0 && $y < 0 && $z < 0);
try {
    DDTrace\install_line_hook(__FILE__, 4, null, 11, null);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
DDTrace\remove_hook($x);
DDTrace\remove_hook($y);
DDTrace\remove_hook($z);

echo "Done.\n";
?>
--EXPECT--
break: b,e,b,e
bool(true)
bool(true)
Line hook range partially overlaps an existing range in the same file
Done.
