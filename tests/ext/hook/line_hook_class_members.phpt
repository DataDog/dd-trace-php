--TEST--
Line hooks resolve inside constructors, static methods, destructors and implemented abstract/interface methods
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

interface I {
    public function iface();
}

abstract class Base implements I {
    public $n;
    public function __construct($n) {
        $this->n = $n * 2;          // 10
    }
    abstract public function absm();
    public static function stat($v) {
        return $v + 1;              // 14
    }
    public function __destruct() {
        echo "dtor\n";              // 17
    }
}

class Impl extends Base {
    public function absm() {
        return 'absm';              // 23
    }
    public function iface() {
        return 'iface';             // 26
    }
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$ids = [];
foreach ([10, 14, 17, 23, 26] as $ln) {
    $ids[] = DDTrace\install_line_hook(__FILE__, $ln, $p);
}

$o = new Impl(5);
var_dump($o->n);
var_dump(Impl::stat(1));
var_dump($o->absm());
var_dump($o->iface());
unset($o);
echo implode(',', $log), "\n";
foreach ($ids as $id) { DDTrace\remove_hook($id); }
echo "Done.\n";
?>
--EXPECT--
int(10)
int(2)
string(4) "absm"
string(5) "iface"
dtor
10,14,23,26,17
Done.
