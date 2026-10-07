--TEST--
Line hooks resolve into anonymous class methods, including one sharing the declaring line
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

function make() {
    return new class {
        public function hi($t) {
            return 'hi:' . $t;      // 9
        }
    };
}

$ids = [];
foreach ([9] as $ln) { $ids[] = DDTrace\install_line_hook(__FILE__, $ln, $p); }

var_dump(make()->hi('a'));
var_dump(make()->hi('b'));
echo implode(',', $log), "\n";
foreach ($ids as $id) { DDTrace\remove_hook($id); }
echo "Done.\n";
?>
--EXPECT--
string(4) "hi:a"
string(4) "hi:b"
9,9
Done.
