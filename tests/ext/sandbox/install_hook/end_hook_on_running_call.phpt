--TEST--
An end-only hook installed while its target is running is delivered for the calls already in flight
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// The per-frame record a hook's handlers share is allocated at frame entry, so a hook installed while its target was on the stack used to be invisible to the calls already running -- which is what "end hooks installed while the function was running won't be hit" meant.
// Such frames are now joined at install time.
//
// Only for a hook with no begin closure: joining one that has a begin would deliver an end whose begin never ran in that frame, breaking the pairing an end hook otherwise guarantees.
// No span is started for a joined frame either, since no begin ran to start one.

function endonly($install) {
    if ($install) {
        DDTrace\install_hook('endonly', null, function (DDTrace\HookData $h) {
            echo "  end(endonly) returned=", var_export($h->returned, true), "\n";
        });
    }
    return 'e';
}

function bothhooks($install) {
    if ($install) {
        DDTrace\install_hook('bothhooks',
            function () { echo "  begin(both)\n"; },
            function () { echo "  end(both)\n"; });
    }
    return 'b';
}

// Several frames of one function in flight at once; the hook goes in at the outermost.
function recur($n, $install) {
    if ($install && $n === 0) {
        DDTrace\install_hook('recur', null, function (DDTrace\HookData $h) {
            echo "  end(recur) returned=", var_export($h->returned, true), "\n";
        });
    }
    if ($n < 2) {
        recur($n + 1, $install);
    }
    return $n;
}

echo "end-only, installed inside its own call:\n";
var_dump(endonly(true));
echo "end-only, fresh call (control):\n";
var_dump(endonly(false));

echo "begin+end, installed inside its own call:\n";
var_dump(bothhooks(true));
echo "begin+end, fresh call (control):\n";
var_dump(bothhooks(false));

echo "end-only, three frames in flight:\n";
var_dump(recur(0, true));

echo "Done.\n";
?>
--EXPECT--
end-only, installed inside its own call:
  end(endonly) returned='e'
string(1) "e"
end-only, fresh call (control):
  end(endonly) returned='e'
string(1) "e"
begin+end, installed inside its own call:
string(1) "b"
begin+end, fresh call (control):
  begin(both)
  end(both)
string(1) "b"
end-only, three frames in flight:
  end(recur) returned=2
  end(recur) returned=1
  end(recur) returned=0
int(0)
Done.
