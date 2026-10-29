--TEST--
A request landing on an abstract method declaration arms that method, whose synthetic return never runs
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

abstract class C {
    abstract public function m();
    public function real() {
        return 'real';                  // 6
    }
}
class D extends C { public function m() { return 'm'; } }

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

// An abstract method still gets an op_array: zend_compile_func_decl() calls zend_emit_final_return() unconditionally with CG(zend_lineno) = decl->end_lineno (Zend/zend_compile.c), and with no body end_lineno *is* the declaration line.
// So C::m is a single ZEND_RETURN attributed to line 4, and it is the innermost op_array carrying the smallest executable lineno >= 4 -- which is where the hook arms.
// C::m is never invoked (D::m overrides it), so nothing fires.
// That is the resolver working as specified rather than swallowing the hook: sliding across the function boundary into real()'s body would silently relocate the hook into a different function.
$a = DDTrace\install_line_hook(__FILE__, 4, $p);
var_dump($a < 0);
$o = new D();
$o->m();
$o->real();
echo 'from 4: ', ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($a);

// Requesting 5 (the real method's signature line) slides into its body at 6, as usual.
$log = [];
$b = DDTrace\install_line_hook(__FILE__, 5, $p);
$o->real();
echo 'from 5: ', ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($b);

echo "Done.\n";
?>
--EXPECT--
bool(true)
from 4: (never fired)
from 5: 6
Done.
