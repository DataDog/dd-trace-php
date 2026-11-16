--TEST--
A begin-only line hook on a trait method fires once for each using class
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

trait T {
    public function m($tag) {
        return $tag . '!';  // 5
    }
}

class A { use T; }
class B { use T; }

$log = [];
$probe = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line . ':' . $h->var('tag'); };
$id = DDTrace\install_line_hook(__FILE__, 5, $probe);

(new A)->m('a');
(new B)->m('b');
(new A)->m('a2');
echo implode(',', $log), "\n";

DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
5:a,5:b,5:a2
Done.
