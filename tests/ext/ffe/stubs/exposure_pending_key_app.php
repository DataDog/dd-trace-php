<?php
require __DIR__ . '/exposure_sidecar.inc';
ffeAwaitExposureSidecar();

function decision($subject, $variant, $flag = 'flag') {
    return DDTrace\Internal\record_ffe_exposure($flag, $subject, array(), 'allocation', $variant);
}
function expectDecision($label, $expected, $actual) {
    if ($actual !== $expected) throw new RuntimeException("$label: expected $expected, got $actual");
}
function fullCount() {
    $stats = json_decode(dd_trace_internal_fn('stats_sidecar'), true);
    if (!isset($stats['ffe_exposures']['full'])) throw new RuntimeException('missing exposure refusal counters');
    return $stats['ffe_exposures']['full'];
}
$initial = fullCount();
for ($i = 0; $i < 2048; ++$i) {
    $subject = 'load-' . $i;
    $status = decision($subject, 'A');
    if ($status === 'admitted') continue;
    if ($status !== 'unavailable' || fullCount() <= $initial || !dd_trace_internal_fn('test_ffe_has_deferred_exposures')) {
        throw new RuntimeException("expected a retained per-route refusal, got $status");
    }
    break;
}
if ($i === 2048) throw new RuntimeException('route never refused an observation');
expectDecision('same key under pressure', 'unavailable', decision($subject, 'B'));
echo "blocked:$subject\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'capacity') throw new RuntimeException('missing capacity barrier');
expectDecision('disjoint A', 'admitted', decision('disjoint', 'A'));
expectDecision('disjoint repeat', 'repeat', decision('disjoint', 'A'));
expectDecision('different flag', 'admitted', decision($subject, 'A', 'other-flag'));
ini_set('datadog.service', 'other-service');
expectDecision('different service', 'admitted', decision($subject, 'A'));
ini_set('datadog.service', 'php-pending-key');
if (!dd_trace_internal_fn('test_ffe_has_deferred_exposures')) throw new RuntimeException('disjoint key consumed pending suffix');
expectDecision('ordered B', 'repeat', decision($subject, 'B'));
expectDecision('ordered C', 'admitted', decision($subject, 'C'));
expectDecision('ordered repeat', 'repeat', decision($subject, 'C'));
echo "decided\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'delivered') throw new RuntimeException('missing delivery barrier');
