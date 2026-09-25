--TEST--
A trait body and the using class's own method on overlapping lines resolve independently
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

trait T {
    public function fromTrait($t) {
        return 'T:' . $t;               // 5
    }
}

class Uses {
    use T;
    public function ownMethod($t) {
        return 'O:' . $t;               // 12
    }
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line . '/' . $h->var('t'); };
$a = DDTrace\install_line_hook(__FILE__, 5, $p);
$b = DDTrace\install_line_hook(__FILE__, 12, $p);

$o = new Uses();
var_dump($o->fromTrait('a'));
var_dump($o->ownMethod('b'));
echo implode(',', $log), "\n";
DDTrace\remove_hook($a);
DDTrace\remove_hook($b);
echo "Done.\n";
?>
--EXPECT--
string(3) "T:a"
string(3) "O:b"
5/a,12/b
Done.
