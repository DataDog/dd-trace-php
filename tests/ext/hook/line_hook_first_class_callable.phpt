--TEST--
First-class callable syntax reaches the same armed line as a direct call
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires PHP 8.1'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function freeFn($t) {
    return 'f:' . $t;               // 4
}

class Holder {
    public function inst($t) {
        return 'i:' . $t;           // 9
    }
    public static function stat($t) {
        return 's:' . $t;           // 12
    }
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$ids = [];
foreach ([4, 9, 12] as $ln) { $ids[] = DDTrace\install_line_hook(__FILE__, $ln, $p); }

$o = new Holder();
$a = freeFn(...);
$b = $o->inst(...);
$c = Holder::stat(...);
var_dump($a('1'), $b('2'), $c('3'));
var_dump(freeFn('4'), $o->inst('5'), Holder::stat('6'));
echo implode(',', $log), "\n";
foreach ($ids as $id) { DDTrace\remove_hook($id); }
echo "Done.\n";
?>
--EXPECT--
string(3) "f:1"
string(3) "i:2"
string(3) "s:3"
string(3) "f:4"
string(3) "i:5"
string(3) "s:6"
4,9,12,4,9,12
Done.
