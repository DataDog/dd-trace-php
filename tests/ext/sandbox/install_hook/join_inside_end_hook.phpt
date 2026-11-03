--TEST--
An end-only hook installed from inside a running end hook does not join the call that is already finishing
--DESCRIPTION--
zai_hook_finish() holds pointers into the frame's hook memory across every end handler, and dd_uhook_end() holds its own `dyn` across the userland call, so growing that block underneath them is a use-after-free.
It is also wrong on its own terms: a call that is already running its end handlers is over, and has no end left to acquire.

Outer frames are a different matter -- they are still executing their bodies, so they are joined as usual.
That is what separates this from end_hook_on_running_call.phpt.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function target($depth) {
    if ($depth > 0) {
        target($depth - 1);
    }
    return $depth;
}

$installed = null;
$outer = DDTrace\install_hook('target', null, function (DDTrace\HookData $h) use (&$installed) {
    if ($installed === null) {
        $installed = DDTrace\install_hook('target', null, function (DDTrace\HookData $g) {
            echo "  inner end depth=", var_export($g->returned, true), "\n";
        });
    }
    echo "outer end depth=", var_export($h->returned, true), "\n";
});
var_dump($outer > 0);

// depth 0 finishes first and is the frame that installs the inner hook: it must not deliver to itself.
// depths 1 and 2 are still mid-body at that moment, so they do get it.
target(2);
echo "---\n";
target(0);

DDTrace\remove_hook($outer);
DDTrace\remove_hook($installed);
echo "Done.\n";
?>
--EXPECT--
bool(true)
outer end depth=0
  inner end depth=1
outer end depth=1
  inner end depth=2
outer end depth=2
---
  inner end depth=0
outer end depth=0
Done.
