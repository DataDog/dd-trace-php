--TEST--
remove_hook() called from inside a range's own end hook drops the hook cleanly
--DESCRIPTION--
Removal during dispatch is deferred and drained on the way out (dd_line_def_drop).
The begin-side and unrelated-frame cases are covered by line_hook_remove.phpt and line_hook_remove_from_end.phpt; this is the end-hook-removes-itself case, where the def is freed while dd_line_close() still holds it.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function target($n) {
    $a = $n;                                // 4  begin
    $b = $a + 1;                            // 5
    return $b;                              // 6  end site
}

function nested($n) {
    return target($n) + target($n + 10);
}

$id = null;
$id = DDTrace\install_line_hook(__FILE__, 4, function () {
    echo "  begin\n";
}, 5, function () use (&$id) {
    echo "  end, removing\n";
    DDTrace\remove_hook($id);
});
var_dump($id < 0);

echo "single call:\n";
var_dump(target(1));
echo "after removal:\n";
var_dump(target(1));

// Reinstall, then remove from the innermost of two sibling calls in one statement.
$id2 = null;
$id2 = DDTrace\install_line_hook(__FILE__, 4, function () {
    echo "  begin2\n";
}, 5, function () use (&$id2) {
    echo "  end2\n";
    DDTrace\remove_hook($id2);
});
var_dump($id2 < 0);
echo "nested:\n";
var_dump(nested(1));

echo "Done.\n";
?>
--EXPECT--
bool(true)
single call:
  begin
  end, removing
int(2)
after removal:
int(2)
bool(true)
nested:
  begin2
  end2
int(14)
Done.
