--TEST--
A join cannot relocate the payload of a frame whose walk is merely suspended
--DESCRIPTION--
zai_hook_finish() and the handlers it calls hold raw pointers into one per-frame allocation.
Refusing to join the frame currently being walked is not enough: a handler may call another hooked function, which becomes the innermost walk, and a join from *there* onto the outer frame would move memory the suspended walk still points at.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function outer_target() { return 1; }
function inner_target() { return 2; }

$installed = null;
DDTrace\install_hook('inner_target', function () use (&$installed) {
    if ($installed === null) {
        $installed = DDTrace\install_hook('outer_target', null, function () { echo "  joined end\n"; });
    }
});
DDTrace\install_hook('outer_target', null, function () {
    inner_target();
});

// outer_target's end calls inner_target, whose begin joins outer_target -- whose walk is suspended, not finished.
var_dump(outer_target());
// A later call is a fresh frame, so the joined hook is delivered normally.
var_dump(outer_target());

echo "Done.\n";
?>
--EXPECT--
int(1)
  joined end
int(1)
Done.
