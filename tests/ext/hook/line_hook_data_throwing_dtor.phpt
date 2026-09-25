--TEST--
Exceptions from objects released with LineHookData propagate to the caller
--DESCRIPTION--
A begin-only hook releases its LineHookData after the callback returns; a range releases it when the range ends.
Both releases run outside the callback sandbox, including when the range ends at frame exit.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

class CleanupThrows {
    public function __destruct() {
        throw new RuntimeException('cleanup escaped');
    }
}

function target() {
    $a = 1;                                 // 10
    $b = 2;                                 // 11
    return $a + $b;                         // 12
}

// Begin-only: the hook data object dies at the end of dispatch.
$begin_only = DDTrace\install_line_hook(__FILE__, 10, function (DDTrace\LineHookData $h) {
    $h->data = new CleanupThrows();
});
var_dump($begin_only < 0);
try {
    target();
} catch (RuntimeException $e) {
    echo 'begin-only: ', $e->getMessage(), "\n";
}
DDTrace\remove_hook($begin_only);

// Range: the object survives from begin to end.
$range = DDTrace\install_line_hook(__FILE__, 10, function (DDTrace\LineHookData $h) {
    $h->data = new CleanupThrows();
}, 11, function () {});
var_dump($range < 0);
try {
    target();
} catch (RuntimeException $e) {
    echo 'range: ', $e->getMessage(), "\n";
}
DDTrace\remove_hook($range);

$frame_exit = DDTrace\install_line_hook(__FILE__, 10, function (DDTrace\LineHookData $h) {
    $h->data = new CleanupThrows();
}, 12, function () {});
var_dump($frame_exit < 0);
try {
    target();
} catch (RuntimeException $e) {
    echo 'frame-exit: ', $e->getMessage(), "\n";
}
DDTrace\remove_hook($frame_exit);

var_dump(target());
echo "Done.\n";
?>
--EXPECT--
bool(true)
begin-only: cleanup escaped
bool(true)
range: cleanup escaped
bool(true)
frame-exit: cleanup escaped
int(3)
Done.
