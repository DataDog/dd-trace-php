--TEST--
A trait in another file, flattened into two classes, fires begin and end for each
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// A trait-flattened method keeps the *trait's* filename, so the hook targets the include even though the classes that run it live here.
// Both classes get their own zend_function over one shared opcodes array, which is what makes the per-scope frame-exit guard load-bearing: a single guard carries the scope of whichever class was resolved first, and zai_hook_continue() skips it for every other class, leaving those frames with no record to open a range in.
require __DIR__ . '/line_hook_cross_file_trait.inc';

class CA { use CrossT; }
class CB { use CrossT; }

$log = [];
$begin = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'begin' . $h->line; };
$end   = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'end' . $h->line; };
$id = DDTrace\install_line_hook(__DIR__ . '/line_hook_cross_file_trait.inc', 5, $begin, 6, $end);

var_dump((new CA)->m('a'));
echo 'CA: ', implode(',', $log), "\n";

$log = [];
var_dump((new CB)->m('b'));
echo 'CB: ', implode(',', $log), "\n";

// The trait's own op_array shares those opcodes too, so removal must leave nothing armed for either class.
$log = [];
DDTrace\remove_hook($id);
(new CA)->m('c');
(new CB)->m('d');
echo 'after remove: ', ($log ? implode(',', $log) : '(nothing)'), "\n";

echo "Done.\n";
?>
--EXPECT--
string(2) "a!"
CA: begin5,end6
string(2) "b!"
CB: begin5,end6
after remove: (nothing)
Done.
