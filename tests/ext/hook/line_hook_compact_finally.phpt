--TEST--
A one-line finally body fires its callbacks on the early-return path as well as on normal completion
--DESCRIPTION--
The compiler emits one FAST_CALL per path that leaves a protected region, each carrying the line of whatever leaves it -- so for `} finally { echo ...; }` the normal-completion FAST_CALL is the first opline holding the finally's own line, and site selection armed that.
A `return` inside the `try` enters the same body through its own FAST_CALL, tagged with the return's line, and so reached the cleanup without ever executing the armed instruction: the early call ran the finally with neither callback.
FAST_CALL is compiler scaffolding rather than a statement, so selection resolves through it to the body, which every path reaches.

Only when the body starts on the FAST_CALL's own line, though.
A `return` inside the `try` enters the finally through its own FAST_CALL carrying the return's line; redirecting that one moved the return's hook onto the cleanup, so it fired on every path through the finally -- including calls that never executed the return.
The second half of this test covers that.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
datadog.trace.hook_limit=0
--FILE--
<?php
function compact_finally($early, $value) {
    try {
        if ($early) { return $value; }
        echo "try $value\n";
    } finally { echo "cleanup $value\n"; }
}
$id = DDTrace\install_line_hook(__FILE__, 6, function () { echo "BEGIN\n"; }, 6, function () { echo "END\n"; });
echo "early:\n";
compact_finally(true, 'value');
echo "normal:\n";
compact_finally(false, 'value');
DDTrace\remove_hook($id);

// A `return` on its own line enters the same finally through its own FAST_CALL.
// Hooking the return must follow the return, not the shared cleanup.
function return_through_finally($early) {
    try {
        if ($early) {
            return;
        }
        echo "normal body\n";
    } finally {
        echo "cleanup\n";
    }
}
$id2 = DDTrace\install_line_hook(__FILE__, 20, function ($h) { echo "BEGIN@", $h->line, "\n"; },
                                            20, function ($h) { echo "END@", $h->line, "\n"; });
echo "not taken:\n";
return_through_finally(false);
echo "taken:\n";
return_through_finally(true);
DDTrace\remove_hook($id2);

// The ordinary layout: finally header on its own line, body on the next.
// The header offers nothing but the normal-entry FAST_CALL, so selection must resolve through it -- but only that one, which try_catch_array identifies as the FAST_CALL two slots before its target's finally_op.
function finally_header($early) {
    try {
        if ($early) { return; }
        echo "normal body\n";
    } finally {
        echo "cleanup\n";
    }
}
$id3 = DDTrace\install_line_hook(__FILE__, 41, function () { echo "H-BEGIN\n"; });
echo "header/early:\n";
finally_header(true);
echo "header/normal:\n";
finally_header(false);
DDTrace\remove_hook($id3);
echo "Done.\n";
?>
--EXPECT--
early:
BEGIN
cleanup value
END
normal:
try value
BEGIN
cleanup value
END
not taken:
normal body
cleanup
taken:
BEGIN@20
END@24
cleanup
header/early:
H-BEGIN
cleanup
header/normal:
normal body
H-BEGIN
cleanup
Done.
