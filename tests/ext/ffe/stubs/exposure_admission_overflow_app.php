<?php
require_once __DIR__ . '/exposure_sidecar.inc';
ffeAwaitExposureSidecar();
$counts = array();
for ($i = 0; $i < 1400; $i++) {
    $status = DDTrace\Internal\record_ffe_exposure('flag', (string) $i, array('index' => $i), 'allocation', 'A', 0, array('arm' => 'control'));
    $counts[$status] = 1 + ($counts[$status] ?? 0);
}
if (function_exists('pcntl_fork')) {
    $pid = pcntl_fork();
    if ($pid === 0) exit(DDTrace\Testing\flush_ffe_exposures() ? 1 : 0);
    if ($pid < 0 || pcntl_waitpid($pid, $code) !== $pid || !pcntl_wifexited($code) || pcntl_wexitstatus($code)) {
        throw new RuntimeException('forked child inherited a deferred handoff');
    }
}
echo isset($counts['unavailable']) && isset($counts['admitted']) ? "overloaded\n" : "unexpected " . json_encode($counts) . "\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'finish') exit(1);
