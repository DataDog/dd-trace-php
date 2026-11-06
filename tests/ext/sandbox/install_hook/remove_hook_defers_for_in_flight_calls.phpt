--TEST--
remove_hook() defers for calls already in flight, identically for install_hook and install_line_hook
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// Removal never drops an $end whose $begin already ran -- that pairing is what callers allocating in $begin and releasing in $end depend on.
// The hook is tombstoned instead (zai_hook_remove_from_entry() negates its id, which zai_hook_continue() skips) and freed by the last frame holding a reference, in zai_hook_finish().
// Both installers go through the same refcount, so both behave the same way; this pins that they stay in step.

$log = [];
$L = function ($m) use (&$log) { $log[] = $m; };

function fn_body($remove) {
    if ($remove) { DDTrace\remove_hook($GLOBALS['id1']); }
    return 'a';
}

function fn_begin() { return 'b'; }

function fn_rec($n) {
    if ($n === 2) { DDTrace\remove_hook($GLOBALS['id3']); }
    if ($n < 2) { fn_rec($n + 1); }
    return $n;
}

function ln_body($remove) {
    $t = 1;
    if ($remove) { DDTrace\remove_hook($GLOBALS['id4']); }
    $t += 2;
    return $t;
}

function ln_rec($n) {
    $t = $n;
    if ($n === 2) { DDTrace\remove_hook($GLOBALS['id5']); }
    if ($n < 2) { ln_rec($n + 1); }
    return $t;
}

// Removed from inside the very call it hooks, after $begin ran.
$GLOBALS['id1'] = DDTrace\install_hook('fn_body',
    function () use ($L) { $L('begin'); }, function () use ($L) { $L('end'); });
$log = []; fn_body(true);  echo "hook, removed mid-call:   ", implode(',', $log), "\n";
$log = []; fn_body(false); echo "hook, later call:         ", ($log ? implode(',', $log) : '(nothing)'), "\n";

// Removed from inside $begin itself.
$GLOBALS['id2'] = DDTrace\install_hook('fn_begin',
    function () use ($L) { $L('begin'); DDTrace\remove_hook($GLOBALS['id2']); },
    function () use ($L) { $L('end'); });
$log = []; fn_begin();     echo "hook, removed in begin:   ", implode(',', $log), "\n";
$log = []; fn_begin();     echo "hook, later call:         ", ($log ? implode(',', $log) : '(nothing)'), "\n";

// Three frames in flight, removed from the innermost: all three must stay paired.
$GLOBALS['id3'] = DDTrace\install_hook('fn_rec',
    function () use ($L) { $L('begin'); }, function () use ($L) { $L('end'); });
$log = []; fn_rec(0);      echo "hook, recursion:          ", implode(',', $log), "\n";
$log = []; fn_rec(0);      echo "hook, later call:         ", ($log ? implode(',', $log) : '(nothing)'), "\n";

// The same two shapes through install_line_hook.
$GLOBALS['id4'] = DDTrace\install_line_hook(__FILE__, 25, function () use ($L) { $L('begin'); }, 27,
                                            function () use ($L) { $L('end'); });
$log = []; ln_body(true);  echo "line, removed mid-range:  ", implode(',', $log), "\n";
$log = []; ln_body(false); echo "line, later call:         ", ($log ? implode(',', $log) : '(nothing)'), "\n";

$GLOBALS['id5'] = DDTrace\install_line_hook(__FILE__, 32, function () use ($L) { $L('begin'); }, 34,
                                            function () use ($L) { $L('end'); });
$log = []; ln_rec(0);      echo "line, recursion:          ", implode(',', $log), "\n";
$log = []; ln_rec(0);      echo "line, later call:         ", ($log ? implode(',', $log) : '(nothing)'), "\n";

echo "Done.\n";
?>
--EXPECT--
hook, removed mid-call:   begin,end
hook, later call:         (nothing)
hook, removed in begin:   begin,end
hook, later call:         (nothing)
hook, recursion:          begin,begin,begin,end,end,end
hook, later call:         (nothing)
line, removed mid-range:  begin,end
line, later call:         (nothing)
line, recursion:          begin,begin,begin,end,end,end
line, later call:         (nothing)
Done.
