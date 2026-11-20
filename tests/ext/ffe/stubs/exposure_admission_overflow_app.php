<?php
require_once __DIR__ . '/exposure_sidecar.inc';
ffeAwaitExposureSidecar();
$required = $uncertain = array();
$deferred = 0;
for ($i = 0; count($required) < 1400 && $i < 4000; $i++) {
    $status = DDTrace\Internal\record_ffe_exposure('flag', (string) $i, array('index' => $i), 'allocation', 'A', 0, array('arm' => 'control'));
    if ($status === 'admitted') {
        $required[] = $i;
    } elseif ($status === 'unknown') {
        $uncertain[] = $i;
    } elseif ($status === 'unavailable') {
        if (dd_trace_internal_fn('test_ffe_has_deferred_exposures')) {
            $required[] = $i;
            $deferred++;
        }
    } else {
        throw new RuntimeException("unexpected verdict $status for subject $i");
    }
}
if (function_exists('pcntl_fork')) {
    $pid = pcntl_fork();
    if ($pid === 0) exit(DDTrace\Testing\flush_ffe_exposures() ? 1 : 0);
    if ($pid < 0 || pcntl_waitpid($pid, $code) !== $pid || !pcntl_wifexited($code) || pcntl_wexitstatus($code)) {
        throw new RuntimeException('forked child inherited a deferred handoff');
    }
}
if ($deferred <= 64 || $deferred > 1000 || count($required) !== 1400) throw new RuntimeException("unexpected deferred count: $deferred");
echo json_encode(array($required, $uncertain)), "\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'finish') throw new RuntimeException('missing delivery barrier');
$remaining = dd_trace_internal_fn('test_ffe_flush_deferred_prefix');
if ($remaining <= 0) throw new RuntimeException('expected an outstanding suffix after one prefix');
while ($remaining > 0) {
    echo "pending:$remaining\n";
    fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'continue') throw new RuntimeException('missing prefix barrier');
    DDTrace\Testing\flush_ffe_exposures();
    $remaining = dd_trace_internal_fn('test_ffe_has_deferred_exposures') ? 1 : 0;
}
echo "flushed\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'delivered') exit(1);
