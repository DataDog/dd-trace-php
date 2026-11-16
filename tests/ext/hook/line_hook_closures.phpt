--TEST--
Line hooks resolve into plain, static and nested closures, and into a closure inside a method
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

$plain = function ($x) {
    return $x + 1;              // 7
};
$stat = static function ($x) {
    return $x + 2;              // 10
};
$nested = function ($x) {
    $inner = function ($y) {
        return $y * 2;          // 14
    };
    return $inner($x);          // 16
};

class Holder {
    public function run($x) {
        $c = function ($y) {
            return $y - 1;      // 22
        };
        return $c($x);          // 24
    }
}

$ids = [];
foreach ([7, 10, 14, 16, 22, 24] as $ln) { $ids[] = DDTrace\install_line_hook(__FILE__, $ln, $p); }

var_dump($plain(1));
var_dump($stat(1));
var_dump($nested(4));
var_dump((new Holder)->run(9));
echo implode(',', $log), "\n";
foreach ($ids as $id) { DDTrace\remove_hook($id); }
echo "Done.\n";
?>
--EXPECT--
int(2)
int(3)
int(8)
int(8)
7,10,16,14,24,22
Done.
