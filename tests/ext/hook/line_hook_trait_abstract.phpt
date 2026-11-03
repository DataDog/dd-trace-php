--TEST--
A hook on a trait's abstract method declaration arms that declaration, which is never invoked
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

trait Needs {
    abstract public function provide();
    public function useIt($t) {
        return $this->provide() . '/' . $t;     // 6
    }
}

class Provider {
    use Needs;
    public function provide() {
        return 'p';                             // 13
    }
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
// Line 4 is the trait's abstract declaration.
// Like any abstract method it owns an op_array holding a single synthetic ZEND_RETURN at that line (zend_emit_final_return() runs unconditionally with lineno = decl->end_lineno), so the hook arms there rather than sliding into useIt().
// Provider::provide() satisfies the requirement, so the abstract stub is never invoked and only the hook on 13 fires.
$a = DDTrace\install_line_hook(__FILE__, 4, $p);
$b = DDTrace\install_line_hook(__FILE__, 13, $p);

var_dump((new Provider)->useIt('t'));
echo implode(',', $log), "\n";
DDTrace\remove_hook($a);
DDTrace\remove_hook($b);
echo "Done.\n";
?>
--EXPECT--
string(3) "p/t"
13
Done.
