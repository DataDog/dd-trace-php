--TEST--
A closure declared inside an anonymous class method is reachable
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php
$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 9, $p);

$obj = new class {
    public function run($t) {
        $inner = function ($u) {
            return 'i:' . $u;           // 9 <- wanted
        };
        return $inner($t);
    }
};

$marker = 1;                            // 15
var_dump($obj->run('a'));
echo ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(3) "i:a"
9
Done.
