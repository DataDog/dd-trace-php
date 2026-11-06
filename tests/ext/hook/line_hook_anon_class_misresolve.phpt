--TEST--
A hook aimed at an anonymous class method must not land on an unrelated file-scope line
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php
$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 8, $p);

$obj = new class {
    public function hi($t) {
        return 'hi:' . $t;              // 8 <- wanted
    }
};

$marker = 1;                            // 12
var_dump($obj->hi('a'));
echo ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(4) "hi:a"
8
Done.
