<?php

require_once __DIR__."/includes/autoload.php";
skip_if_php5();

$telemetryLogPath = tempnam(sys_get_temp_dir(), 'test_loader_');

// Thread-mode sidecars stop when PHP exits, so wait for telemetry completion
// inside the instrumented process while its sidecar is still running.
$code = <<<'PHP'
echo 'foo';
dd_trace_internal_fn('finalize_telemetry');
$telemetryLogPath = substr(getenv('DD_TRACE_AGENT_URL'), strlen('file://'));
$deadline = microtime(true) + 5;
do {
    usleep(10000);
    $content = file_get_contents($telemetryLogPath);
    if (strpos($content, '"request_type":"app-closing"') !== false) {
        break;
    }
} while (microtime(true) < $deadline);
PHP;

$output = runCLI('-r '.escapeshellarg($code), true, [
    'DD_TRACE_AGENT_URL=file://'.$telemetryLogPath,
    'DD_TRACE_LOG_LEVEL=debug,startup',
    'DD_INJECT_FORCE=true',
    'DD_INJECTION_ENABLED=tracer', // Normally set by the injector
    'DD_SERVICE=loader',
    'DD_TRACE_GENERATE_ROOT_SPAN=0',
    'DD_API_KEY=SENTINEL_DD_API_KEY',
    'DD_VERSION=1.2.3-loader-test',
]);

assertMatchesFormat($output, '%A"loaded_by_ssi":true%s%A');

$instrumentationSource = '{"name":"instrumentation_source","value":"ssi","origin":"default","config_id":null,"seq_id":null}';
$content = file_get_contents($telemetryLogPath);

assertContains($content, '"request_type":"app-closing"');
assertContains($content, $instrumentationSource);
assertContains($content, '{"name":"ssi_injection_enabled","value":"tracer","origin":"env_var","config_id":null,"seq_id":null}');
assertContains($content, '{"name":"ssi_forced_injection_enabled","value":"True","origin":"env_var","config_id":null,"seq_id":null}');

assertNotContains($content, 'SENTINEL_DD_API_KEY');
assertNotContains($content, '"name":"DD_API_KEY"');
assertNotContains($content, '"name":"DD_TRACE_ENABLED"');

assertContains($content, '{"name":"DD_VERSION","value":"1.2.3-loader-test","origin":"env_var","config_id":null,"seq_id":null}');
