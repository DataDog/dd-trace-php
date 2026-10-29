--TEST--
A line inside a closure inside a closure inside a method resolves to the innermost body
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

class Deep {
    public function outer($t) {
        $mid = function ($u) {
            $inner = function ($v) {
                return 'deep:' . $v;            // 7
            };
            return $inner($u . '/mid');         // 9
        };
        return $mid($t);                        // 11
    }
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$ids = [];
foreach ([7, 9, 11] as $ln) { $ids[] = DDTrace\install_line_hook(__FILE__, $ln, $p); }

var_dump((new Deep)->outer('t'));
echo implode(',', $log), "\n";
foreach ($ids as $id) { DDTrace\remove_hook($id); }
echo "Done.\n";
?>
--EXPECT--
string(10) "deep:t/mid"
11,9,7
Done.
