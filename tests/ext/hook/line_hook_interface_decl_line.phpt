--TEST--
A request landing on an interface method declaration arms that method, whose synthetic return never runs
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

interface I {
    public function m();
}

class Impl implements I {
    public function m() {
        return 'm';                     // 9
    }
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

// Same as line_hook_abstract_decl_line: an interface method gets an op_array holding one synthetic ZEND_RETURN at its declaration line, so line 4 resolves into I::m -- which is never invoked.
// The arm is where the line is; the hook not firing is a property of the program, not a resolution error.
$a = DDTrace\install_line_hook(__FILE__, 4, $p);
var_dump($a < 0);
(new Impl)->m();
echo 'from 4: ', ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($a);

$log = [];
$b = DDTrace\install_line_hook(__FILE__, 9, $p);
(new Impl)->m();
echo 'from 9: ', ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($b);

echo "Done.\n";
?>
--EXPECT--
bool(true)
from 4: (never fired)
from 9: 9
Done.
