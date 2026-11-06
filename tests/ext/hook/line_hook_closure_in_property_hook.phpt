--TEST--
A closure declared inside a property hook body is reachable
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip requires PHP 8.4'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php
$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 10, $p);

class C {
    public string $v {
        get {
            $f = function () {
                return 'inner';         // 10 <- wanted
            };
            return $f();
        }
    }
}

$marker = 1;                            // 17
var_dump((new C)->v);
echo ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(5) "inner"
10
Done.
