--TEST--
A line hook installed before its file is included arms when the file is finally compiled
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$hits = 0;
// Nothing to arm yet: the file has not been compiled.
$id = DDTrace\install_line_hook('line_hook_later_include.inc', 4, function () use (&$hits) { ++$hits; });
var_dump($id < 0);
var_dump($hits);

require __DIR__ . '/line_hook_later_include.inc';

var_dump(later_target(1));
var_dump(later_target(2));
var_dump($hits);

DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
bool(true)
int(0)
int(2)
int(3)
int(2)
Done.
