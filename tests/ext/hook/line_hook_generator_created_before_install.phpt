--TEST--
A range in a generator created before the hook was installed closes, and one whose begin was already passed is ignored
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// A generator's per-frame record is created when the *generator* is created, not on resume, so a generator predating the hook has none -- whether it is suspended at a yield or has never been started at all.
// Such a frame is joined lazily when a range first opens in it.
// PHP 8 files that record under the generator object, PHP 7 under the frame and in a larger shape carrying the yield-detection ops -- which are left zeroed, since the yield handler arms them at the next ZEND_YIELD.
// Neither version needs the delivery chain touched: on PHP 8 zend_observer_generator_resume() re-pushes the frame on every resume, and PHP 7 has no chain at all.

function once() {
    $a = 1;
    yield 1;
    $a = 2;
    yield 2;
}

function loop() {
    foreach ([1, 2] as $i) {
        $a = $i;
        yield $i;
        $a = 0;
    }
}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };

// Created, never started: the hook exists by the time the body is first entered, so the range opens and closes.
$never = once();
$id = DDTrace\install_line_hook(__FILE__, 9, $b, 11, $e);
$log = [];
foreach ($never as $v) {}
echo "never started:      ", ($log ? implode(',', $log) : '(nothing)'), "\n";
DDTrace\remove_hook($id);

// Suspended past the begin line: the range never began, so it must stay ignored rather than close spuriously.
$past = once();
$past->current();
$id = DDTrace\install_line_hook(__FILE__, 9, $b, 11, $e);
$log = [];
foreach ($past as $v) {}
echo "begin already past:  ", ($log ? implode(',', $log) : '(nothing)'), "\n";
DDTrace\remove_hook($id);

// Suspended at a yield inside a loop, so a later iteration reaches the begin line again after the install.
$mid = loop();
$mid->current();
$id = DDTrace\install_line_hook(__FILE__, 17, $b, 19, $e);
$log = [];
foreach ($mid as $v) {}
echo "loop, later pass:    ", ($log ? implode(',', $log) : '(nothing)'), "\n";
DDTrace\remove_hook($id);

echo "Done.\n";
?>
--EXPECT--
never started:      B9,E12
begin already past:  (nothing)
loop, later pass:    B17,E16
Done.
