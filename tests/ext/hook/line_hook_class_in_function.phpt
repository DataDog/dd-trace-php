--TEST--
A class declared inside a function body is reachable even when hooked before the function runs
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php
$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 9, $p);

function declareIt() {
    class Nested {
        public function m($t) {
            return 'n:' . $t;           // 9 <- wanted
        }
    }
}

$marker = 1;                            // 14
declareIt();
var_dump((new Nested)->m('a'));
echo ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(3) "n:a"
9
Done.
