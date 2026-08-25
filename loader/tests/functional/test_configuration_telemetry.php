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
    'DD_TRACE_GENERATE_ROOT_SPAN=0',
]);

assertMatchesFormat($output, '%A"loaded_by_ssi":true%s%A');

// The sidecar writes asynchronously. Poll for the first expected entry instead
// of assuming it will always start and flush within a fixed delay.
$instrumentationSource = '{"name":"instrumentation_source","value":"ssi","origin":"default","config_id":null,"seq_id":null}';
$deadline = microtime(true) + 5;
do {
    usleep(10000);
    $content = file_get_contents($telemetryLogPath);
    if (strpos($content, $instrumentationSource) !== false) {
        break;
    }
} while (microtime(true) < $deadline);

assertContains($content, $instrumentationSource);
assertContains($content, '{"name":"ssi_injection_enabled","value":"tracer","origin":"env_var","config_id":null,"seq_id":null}');
assertContains($content, '{"name":"ssi_forced_injection_enabled","value":"True","origin":"env_var","config_id":null,"seq_id":null}');
