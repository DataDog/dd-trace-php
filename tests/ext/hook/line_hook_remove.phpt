--TEST--
remove_hook() from inside a line hook's own callback, and removal deferred while a range is open
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function once() {
    $a = 1;
    return $a;
}

function loop3() {
    $r = 0;
    for ($i = 0; $i < 3; $i++) {
        $r += $i;
        $r += 10;
    }
    return $r;
}

// A callback runs userland, so it may remove the very hook being dispatched -- which frees the def and the site table the dispatcher is walking.
// Removal is therefore deferred to the end of dispatch.
$log = [];
$a = null;
$a = DDTrace\install_line_hook(__FILE__, 4, function () use (&$log, &$a) {
    $log[] = 'begin';
    DDTrace\remove_hook($a);
});
once();
once();
echo "self-remove: ", implode(',', $log), "\n";

// Removing while a range is open must not skip the end hook: no further begin fires, but the open range still closes at its own end site rather than at some arbitrary opline.
$log = [];
$b = null;
$b = DDTrace\install_line_hook(
    __FILE__,
    11,
    function () use (&$log, &$b) { $log[] = 'B'; DDTrace\remove_hook($b); },
    12,
    function () use (&$log) { $log[] = 'E'; }
);
var_dump(loop3());
echo "deferred: ", implode(',', $log), "\n";

// Fully gone afterwards, so a fresh install on the same line works.
$log = [];
$c = DDTrace\install_line_hook(__FILE__, 11, function () use (&$log) { $log[] = 'again'; });
loop3();
DDTrace\remove_hook($c);
loop3();
echo "reinstall: ", count($log), "\n";

echo "Done.\n";
?>
--EXPECT--
self-remove: begin
int(33)
deferred: B,E
reinstall: 3
Done.
