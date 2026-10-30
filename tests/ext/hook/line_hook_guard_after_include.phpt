--TEST--
Removing a range whose op_array has already been destroyed does not touch freed guard records
--DESCRIPTION--
A range installs a guard hook per (opcodes, scope), refcounted so that removing the last range over a function also removes its guard.
The op_array can die first, though -- an uncached include returning is enough -- and that takes the guard set with it while the definition still points at the records.
The record therefore needs an allocation lifetime separate from its install count, or remove_hook() decrements freed memory.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
opcache.enable_cli=0
--FILE--
<?php

$target = __DIR__ . '/line_hook_guard_after_include.inc';
$id = DDTrace\install_line_hook($target, 2, function () { echo "begin\n"; },
                                         3, function () { echo "end\n"; });
var_dump($id < 0);
var_dump(require $target);

// The include's op_array is gone by now; the definition still holds its guard claim.
$strings = [];
for ($i = 0; $i < 32; $i++) {
    $strings[] = str_repeat('X', 15);
}
DDTrace\remove_hook($id);
foreach ($strings as $i => $s) {
    if ($s !== str_repeat('X', 15)) {
        echo "corrupted string $i: ", bin2hex($s), "\n";
    }
}

echo "Done.\n";
?>
--EXPECT--
bool(true)
begin
end
int(2)
Done.
