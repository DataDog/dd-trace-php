--TEST--
A trait method used by two classes fires once for each, and every begin gets exactly one end
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

trait T {
    public function m($tag) {
        $a = $tag;          // 5  begin
        $b = $a . '!';      // 6
        return $b;          // 7  end
    }
}

class A { use T; }
class B { use T; }

$log = [];
$begin = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'begin' . $h->line; };
$end   = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'end' . $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 5, $begin, 7, $end);

var_dump((new A)->m('a'));
echo 'A: ', implode(',', $log), "\n";

$log = [];
var_dump((new B)->m('b'));
echo 'B: ', implode(',', $log), "\n";

DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(2) "a!"
A: begin5,end7
string(2) "b!"
B: begin5,end7
Done.
