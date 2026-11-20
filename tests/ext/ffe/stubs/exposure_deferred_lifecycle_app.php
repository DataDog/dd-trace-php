<?php
require __DIR__ . '/exposure_sidecar.inc';
ffeAwaitExposureSidecar();
function observe($subject, $attributes, $allocation = 'alloc') {
    return DDTrace\Internal\record_ffe_exposure('flag', $subject, $attributes, $allocation, 'blue', 0);
}
$blob = array('blob' => str_repeat('x', 70 * 1024));
if (observe('before', $blob) !== 'unavailable' || !dd_trace_internal_fn('test_ffe_has_deferred_exposures')) {
    throw new RuntimeException('expected a retained oversize row');
}
observe('invalid', array(), '');
ini_set('datadog.feature_flags_exposures_datadog_logging_enabled', '0');
if (observe('only', array()) !== 'admitted' || observe('only', array()) !== 'repeat') {
    throw new RuntimeException('expected a shared hook-only decision');
}
ini_set('datadog.feature_flags_exposures_datadog_logging_enabled', '1');
ini_set('datadog.service', 'deferred-after');
if (observe('shutdown', $blob) !== 'unavailable') throw new RuntimeException('expected shutdown handoff');
