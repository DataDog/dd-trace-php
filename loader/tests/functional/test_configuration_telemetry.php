<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

$output = runCLI('-r "echo \'foo\'; dd_trace_internal_fn(\'finalize_telemetry\');"', true, [
    'DD_TRACE_AGENT_URL=file://'.$telemetryLogPath,
    'DD_TRACE_LOG_LEVEL=debug,startup',
    'DD_INJECT_FORCE=true',
    'DD_INJECTION_ENABLED=tracer', // Normally set by the injector
    'DD_SERVICE=loader',
    'DD_API_KEY=SENTINEL_DD_API_KEY',
    'DD_VERSION=1.2.3-loader-test',
]);

assertMatchesFormat($output, '%A"loaded_by_ssi":true%s%A');

// Let time to write the telemetry log
usleep(300000);

$content = file_get_contents($telemetryLogPath);
assertContains($content, '{"name":"instrumentation_source","value":"ssi","origin":"default","config_id":null,"seq_id":null}');
assertContains($content, '{"name":"ssi_injection_enabled","value":"tracer","origin":"env_var","config_id":null,"seq_id":null}');
assertContains($content, '{"name":"ssi_forced_injection_enabled","value":"True","origin":"env_var","config_id":null,"seq_id":null}');

// Sensitive configurations are excluded from configuration telemetry: neither
// the name nor the value is enqueued. DD_API_KEY and DD_TRACE_ENABLED carry the
// `sensitive` flag.
assertNotContains($content, 'SENTINEL_DD_API_KEY');
assertNotContains($content, '"name":"DD_API_KEY"');
assertNotContains($content, '"name":"DD_TRACE_ENABLED"');

// Non-sensitive configurations are still reported.
assertContains($content, '{"name":"DD_VERSION","value":"1.2.3-loader-test","origin":"env_var","config_id":null,"seq_id":null}');
