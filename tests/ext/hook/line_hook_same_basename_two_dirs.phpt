--TEST--
Forward resolution picks a line per file, not one line shared across every file that suffix-matches
--DESCRIPTION--
dd_line_resolve() ran its first pass over every candidate op_array in every matching file and kept a single minimum, then armed only the op_arrays carrying exactly that line.
Two files with the same basename whose nearest executable line differs therefore shared one answer, and whichever file resolved lower silently took the hook for both.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// a/same_target.inc has its statement on line 4; b/same_target.inc has a blank line there and its statement on 5.
require __DIR__ . '/line_hook_samename/a/same_target.inc';
require __DIR__ . '/line_hook_samename/b/same_target.inc';

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) {
    $log[] = basename(dirname($h->file)) . ':' . $h->line;
};

$id = DDTrace\install_line_hook('same_target.inc', 4, $p);
var_dump($id < 0);
var_dump(samename_a());
var_dump(samename_b());
sort($log);
echo implode(',', $log), "\n";
DDTrace\remove_hook($id);

echo "Done.\n";
?>
--EXPECT--
bool(true)
string(2) "a4"
string(2) "b5"
a:4,b:5
Done.
