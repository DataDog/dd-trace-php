--TEST--
A line hook whose own callback body sits on the armed line must not recurse
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php
$hits = 0;
function tgt() { return 1; } $p = function (DDTrace\LineHookData $h) use (&$hits) { ++$hits; return tgt(); };
$id = DDTrace\install_line_hook(__FILE__, 3, $p);

tgt();
var_dump($hits);
tgt();
var_dump($hits);
DDTrace\remove_hook($id);
tgt();
var_dump($hits);
echo "Done.\n";
?>
--EXPECT--
int(1)
int(2)
int(2)
Done.
