<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

$sidecarLogPath = tempnam(sys_get_temp_dir(), 'sidecar_loader_');
$output = runCLI('-r "echo \'foo\'; dd_trace_internal_fn(\'finalize_telemetry\');"', true, [
    'DD_TRACE_AGENT_URL=file://'.$telemetryLogPath,
    'DD_TRACE_LOG_LEVEL=debug,startup',
    'DD_INJECT_FORCE=true',
    'DD_INJECTION_ENABLED=tracer', // Normally set by the injector
    'DD_SERVICE=loader',
    '_DD_DEBUG_SIDECAR_LOG_LEVEL=trace',
    '_DD_DEBUG_SIDECAR_LOG_METHOD=file://'.$sidecarLogPath,
]);

assertMatchesFormat($output, '%A"loaded_by_ssi":true%s%A');

// Let time to write the telemetry log
usleep(300000);

$content = file_get_contents($telemetryLogPath);

// On failure: dump diagnostic info so CI logs show what happened
if (strpos($content, '"instrumentation_source"') === false) {
    fwrite(STDERR, "\n=== Sidecar log (" . $sidecarLogPath . ") ===\n");
    fwrite(STDERR, file_get_contents($sidecarLogPath) ?: "(empty)\n");
    // Dump any crash files left by direct_entry.c crash_handler
    foreach (glob('/tmp/ddog_sidecar_crash_*') ?: [] as $f) {
        fwrite(STDERR, "\n=== Crash file: $f ===\n");
        fwrite(STDERR, file_get_contents($f) ?: "(empty)\n");
    }
    fwrite(STDERR, "\n=== Telemetry file (" . $telemetryLogPath . ", " . strlen($content) . " bytes) ===\n");
    fwrite(STDERR, substr($content, 0, 1024) ?: "(empty)\n");
}

assertContains($content, '{"name":"instrumentation_source","value":"ssi","origin":"default","config_id":null,"seq_id":null}');
assertContains($content, '{"name":"ssi_injection_enabled","value":"tracer","origin":"env_var","config_id":null,"seq_id":null}');
assertContains($content, '{"name":"ssi_forced_injection_enabled","value":"True","origin":"env_var","config_id":null,"seq_id":null}');
