--TEST--
Diagnostic: anonymous class methods, with every class already linked before the hook is installed
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

interface Marker {}
class Parent1 {}
trait Tr {}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

function bare() {
    return new class {
        public function m() { return 'bare'; }          // 12
    };
}
function withParent() {
    return new class extends Parent1 {
        public function m() { return 'parent'; }        // 17
    };
}
function withIface() {
    return new class implements Marker {
        public function m() { return 'iface'; }         // 22
    };
}
function withTrait() {
    return new class { use Tr;
        public function m() { return 'trait'; }         // 27
    };
}

// Link every anonymous class before any hook is installed, so resolution is not a timing question.
foreach (['bare', 'withParent', 'withIface', 'withTrait'] as $fn) { $fn()->m(); }

foreach ([12 => 'bare', 17 => 'withParent', 22 => 'withIface', 27 => 'withTrait'] as $ln => $fn) {
    $id = DDTrace\install_line_hook(__FILE__, $ln, $p);
    $log = [];
    $fn()->m();
    echo "$fn (line $ln) -> ", ($log ? implode(',', $log) : '(never fired)'), "\n";
    DDTrace\remove_hook($id);
}

echo "Done.\n";
?>
--EXPECT--
bare (line 12) -> 12
withParent (line 17) -> 17
withIface (line 22) -> 22
withTrait (line 27) -> 27
Done.
