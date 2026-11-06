--TEST--
A hook on a trait method that every using class overrides arms dead code and never fires
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

trait T {
    public function m($t) {
        return 'trait:' . $t;       // 5  never reachable: every user overrides m()
    }
}

class C {
    use T;
    public function m($t) {
        return 'class:' . $t;       // 12
    }
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

// Line 5 is real code inside T::m, so that is where the hook arms -- correctly.
// C overrides m(), so T::m is dead and the hook never fires.
// A file:line hook resolves to the code at that line; whether that code is reachable is the program's business.
// Sliding to 12 would mean firing for a *different* function than the one the user pointed at.
$a = DDTrace\install_line_hook(__FILE__, 5, $p);
var_dump((new C)->m('x'));
echo 'from 5: ', ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($a);

$log = [];
$b = DDTrace\install_line_hook(__FILE__, 12, $p);
var_dump((new C)->m('y'));
echo 'from 12: ', ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($b);

echo "Done.\n";
?>
--EXPECT--
string(7) "class:x"
from 5: (never fired)
string(7) "class:y"
from 12: 12
Done.
