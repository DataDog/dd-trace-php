--TEST--
FFE reports redacted configuration failures and once-only diagnostics on first use
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !getenv('TEST_PHP_EXECUTABLE')) die('skip: PHP child process required');
?>
--ENV--
DD_TRACE_ENABLED=0
DD_TRACE_CLI_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_STARTUP_LOGS=0
DD_TRACE_AGENT_URL=http://127.0.0.1:1
--FILE--
<?php
require_once __DIR__ . '/stubs/ffe_process.inc';
$base = getenv();
foreach (array('DD_FEATURE_FLAGS_ENABLED', 'DD_FEATURE_FLAGS_CONFIGURATION_SOURCE', 'DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED') as $key) unset($base[$key]);
$base['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL'] = '';
$base['DD_API_KEY'] = '';
$base['DD_SITE'] = 'datadoghq.com';
$base['DD_TRACE_LOG_LEVEL'] = 'warn';
$cases = array(
    'missing_key' => array(array(), 'missing_api_key', array('requires DD_API_KEY')),
    'source' => array(array('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE' => 'secret-source'), 'invalid_source', array('configuration source is invalid')),
    'endpoint' => array(array('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL' => 'ftp://secret-user:secret-password@example.com/path?key=secret-query'), 'invalid_endpoint', array('CDN base URL must')),
    'site' => array(array('DD_API_KEY' => 'secret-key', 'DD_SITE' => 'secret-site/path'), 'invalid_site', array('requires a valid DD_SITE')),
    'timings' => array(array('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE' => 'offline', 'DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS' => '0', 'DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS' => '-1', 'DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS' => '0'), 'disabled', array('using 30 seconds', 'using 5 seconds', 'using 10000 milliseconds')),
    'legacy' => array(array('DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED' => '0'), 'disabled', array('DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED is deprecated')),
);
foreach ($cases as $name => $case) {
    $log = tempnam(sys_get_temp_dir(), 'ffe-settings-');
    $environment = array_merge($base, $case[0], array('DD_TRACE_LOG_FILE' => $log));
    $process = ffeTestProcess(__DIR__ . '/stubs/agentless_settings_app.php', $pipes, $environment);
    $stdout = $stderr = '';
    $deadline = microtime(true) + 60;
    do {
        $stdout .= ffeTestProcessRead($pipes[1]);
        if (strpos($stdout, "fixture_child_ready\n") !== false) break;
        usleep(10000);
    } while (microtime(true) < $deadline);
    if (strpos($stdout, "fixture_child_ready\n") === false) throw new RuntimeException('child startup timed out');
    foreach ($case[2] as $message) {
        if (strpos(file_get_contents($log), $message) !== false) throw new RuntimeException('diagnostic emitted before feature use');
    }
    fwrite($pipes[0], "evaluate\n");
    fclose($pipes[0]);
    do {
        $stdout .= ffeTestProcessRead($pipes[1]);
        $stderr .= ffeTestProcessRead($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) break;
        usleep(10000);
    } while (microtime(true) < $deadline);
    $stdout .= ffeTestProcessRead($pipes[1]);
    if ($status['running']) proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($status['running'] || $status['exitcode'] !== 0) throw new RuntimeException($name . ' failed: ' . $stderr);
    $result = json_decode(str_replace("fixture_child_ready\n", '', $stdout), true);
    if ($result['state']['reason'] !== $case[1] || $result['value'] !== 'fallback') throw new RuntimeException($name . ' wrong result: ' . $stdout);
    if ($case[1] !== 'disabled' && $result['error'] !== $result['state']['errorMessage']) throw new RuntimeException('caller lost the failure reason');
    $diagnostics = file_get_contents($log);
    unlink($log);
    foreach ($case[2] as $message) {
        if (substr_count($diagnostics, $message) !== 1) throw new RuntimeException($name . ' diagnostic missing or repeated: ' . $diagnostics);
    }
    if (strpos($diagnostics . $stdout . $stderr, 'secret-') !== false) throw new RuntimeException('configuration secret leaked');
    echo $name, "=ok\n";
}
?>
--EXPECT--
missing_key=ok
source=ok
endpoint=ok
site=ok
timings=ok
legacy=ok
