--TEST--
FFE exposures too large for one sidecar message are dropped with one warning; the others are sent
--ENV--
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
--INI--
datadog.trace.log_file={PWD}/exposure_partial_drop.log
datadog.trace.log_level=warn
datadog.trace.generate_root_span=0
datadog.experimental_flagging_provider_enabled=1
--FILE--
<?php
require __DIR__ . '/logging.inc';

// Larger than one IPC message (4 MiB by default).
$oversized = array('blob' => str_repeat('x', 5 * 1024 * 1024));
foreach (array('first', 'second') as $round) {
    DDTrace\Internal\record_ffe_exposure('flag', "$round-oversized", $oversized, 'allocation', 'on');
    DDTrace\Internal\record_ffe_exposure('flag', "$round-normal", array(), 'allocation', 'on');
    echo $round, '_flush=', json_encode(DDTrace\Testing\flush_ffe_exposures()), "\n";
}

// PCRE's JIT reports uninitialised reads under Valgrind, so match with strpos.
function count_lines($logs, $level, $text) {
    $count = 0;
    foreach (explode("\n", $logs) as $line) {
        if (strpos($line, "[$level]") !== false && strpos($line, $text) !== false) {
            ++$count;
        }
    }
    return $count;
}
$logs = read_log_snapshot(__DIR__ . '/exposure_partial_drop.log');
echo 'warnings=', count_lines($logs, 'warning', 'Feature Flags: dropped 1 exposure(s) larger than one IPC message; the others were sent'), "\n";
echo 'errors=', count_lines($logs, 'error', 'Feature Flags') + count_lines($logs, 'error', 'FFE'), "\n";
?>
--EXPECT--
first_flush=true
second_flush=true
warnings=1
errors=0
--CLEAN--
<?php @unlink(__DIR__ . '/exposure_partial_drop.log'); ?>
