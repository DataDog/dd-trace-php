--TEST--
A line-hook callback that retro-joins the frame it is standing in does not invalidate the dispatcher's range state
--DESCRIPTION--
dd_line_dispatch() resolves the frame's range state once, before running any callback, and then uses it for every def armed at the opline.
An end-only DDTrace\install_hook() from inside the first callback joins the very frame we are dispatching in, which grows that frame's hook memory -- so the state pointer the second def is about to use must not live inside the block that moved.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function target() {
    $a = 1;                                 // 4
    $b = 2;                                 // 5
    return $a + $b;                         // 6
}

$joined = null;

// Equal range bounds preserve registration order, so A runs first at the shared site.
$a = DDTrace\install_line_hook(__FILE__, 4, function () use (&$joined) {
    if ($joined === null) {
        $joined = DDTrace\install_hook('target', null, function () { echo "  joined end\n"; });
    }
    echo "  A begin\n";
}, 5);

// Armed second: a range, so dispatch is holding range state across A's callback.
$b = DDTrace\install_line_hook(__FILE__, 4, function () { echo "  B begin\n"; }, 5,
                                            function () { echo "  B end\n"; });
var_dump($a < 0 && $b < 0);

echo "call 1:\n";
var_dump(target());
echo "call 2:\n";
var_dump(target());

DDTrace\remove_hook($a);
DDTrace\remove_hook($b);
DDTrace\remove_hook($joined);
echo "Done.\n";
?>
--EXPECT--
bool(true)
call 1:
  A begin
  B begin
  B end
  joined end
int(3)
call 2:
  A begin
  B begin
  B end
  joined end
int(3)
Done.
