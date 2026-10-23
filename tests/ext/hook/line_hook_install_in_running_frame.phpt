--TEST--
A range opened in a frame that was already running when the hook was installed still closes
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// The per-frame record a range lives in is allocated when the frame is entered, so a frame already on the stack when the hook was installed used to have none: $begin fired and $end never did -- not at $endLine, not at an exit edge, not at frame exit.
// The frame is now joined lazily at the moment a range actually opens in it, which is the only moment it can matter: a frame that has already passed begin_op has nothing left to close.

function ranged($install, $early) {
    if ($install) {
        $GLOBALS['id'] = DDTrace\install_line_hook(__FILE__, 10, $GLOBALS['b'], 14, $GLOBALS['e']);
    }
    $t = 1;
    if ($early) {
        return 'early';
    }
    $t += 2;
    return $t;
}

function installer() {
    $GLOBALS['id'] = DDTrace\install_line_hook(__FILE__, 26, $GLOBALS['b'], 27, $GLOBALS['e']);
}

function caller($install) {
    if ($install) {
        installer();
    }
    $t = 1;
    $t += 2;
    return $t;
}

$log = [];
$GLOBALS['b'] = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$GLOBALS['e'] = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };

$log = []; var_dump(ranged(true, false));  echo "inside, reaches endLine: ", implode(',', $log), "\n";
$log = []; var_dump(ranged(false, false)); echo "fresh frame (control):   ", implode(',', $log), "\n";
DDTrace\remove_hook($GLOBALS['id']);

// Leaving the range by return: no jump operand carries that, so only the frame-exit guard can close it.
$log = []; var_dump(ranged(true, true));   echo "inside, returns early:   ", implode(',', $log), "\n";
DDTrace\remove_hook($GLOBALS['id']);

// Installed from a callee, so the frame that later opens the range was mid-flight and deeper than the installer.
$log = []; var_dump(caller(true));         echo "installed from callee:   ", implode(',', $log), "\n";
$log = []; var_dump(caller(false));        echo "fresh frame (control):   ", implode(',', $log), "\n";
DDTrace\remove_hook($GLOBALS['id']);

echo "Done.\n";
?>
--EXPECT--
int(3)
inside, reaches endLine: B10,E15
int(3)
fresh frame (control):   B10,E15
string(5) "early"
inside, returns early:   B10,E14
int(3)
installed from callee:   B26,E28
int(3)
fresh frame (control):   B26,E28
Done.
