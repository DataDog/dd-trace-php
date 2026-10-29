--TEST--
Diagnostic: an anonymous class method, hooked after the class has already been linked
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

// Link the anonymous class first, then install.
make()->hi('warmup');

$id = DDTrace\install_line_hook(__FILE__, 9, $p);
var_dump(make()->hi('a'));
echo 'after link: ', ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(4) "hi:a"
after link: 9
Done.
