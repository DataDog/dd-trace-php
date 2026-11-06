--TEST--
Diagnostic: which class binding kinds a line hook can reach
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

class EarlyBound {
    public function m() { return 'early'; }         // 7
}

if (!class_exists('Conditional')) {
    class Conditional {
        public function m() { return 'cond'; }      // 12
    }
}

function makeAnon() {
    return new class {
        public function m() { return 'anon'; }      // 18
    };
}

class Child extends LateParent {
    public function m() { return 'child'; }         // 23
}

class LateParent {
}

foreach ([7, 12, 18, 23] as $ln) {
    $id = DDTrace\install_line_hook(__FILE__, $ln, $p);
    $log = [];
    (new EarlyBound)->m();
    (new Conditional)->m();
    makeAnon()->m();
    (new Child)->m();
    echo "request $ln -> ", ($log ? implode(',', $log) : '(never fired)'), "\n";
    DDTrace\remove_hook($id);
}

echo "Done.\n";
?>
--EXPECT--
request 7 -> 7
request 12 -> 12
request 18 -> 18
request 23 -> 23
Done.
