--TEST--
Zero exposure-admission budget defers eligible delivery rows and bypasses hook-only IPC
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=1
DD_FEATURE_FLAGS_EXPOSURES_ADMISSION_TIMEOUT_US=0
DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED=false
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_SERVICE=php-batch-only
_DD_DEBUG_SIDECAR_IPC_MODE=instance_per_process
_DD_DEBUG_SIDECAR_IDLE_LINGER_TIME_SECS=45
--FILE--
<?php
require __DIR__ . '/stubs/exposure_sidecar.inc';
ffeAwaitExposureSidecar();
function observe() {
    return DDTrace\Internal\record_ffe_exposure('flag', 'user', array(), 'alloc', 'blue');
}
var_dump(observe(), dd_trace_internal_fn('test_ffe_has_deferred_exposures'));
ini_set('datadog.feature_flags_exposures_datadog_logging_enabled', '1');
var_dump(observe(), dd_trace_internal_fn('test_ffe_has_deferred_exposures'));
?>
--EXPECT--
string(11) "unavailable"
bool(false)
string(11) "unavailable"
bool(true)
