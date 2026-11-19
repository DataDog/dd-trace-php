<?php
require_once __DIR__ . '/ffe_process.inc';
$base = getenv();
foreach (array('DD_FEATURE_FLAGS_ENABLED', 'DD_FEATURE_FLAGS_CONFIGURATION_SOURCE', 'DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED') as $key) unset($base[$key]);
$base['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL'] = '';
$base['DD_API_KEY'] = '';
$base['DD_SITE'] = 'datadoghq.com';
$base['DD_TRACE_LOG_LEVEL'] = 'warn';
$cases = array(
    'missing_key' => array(array('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE' => 'agentless'), 'missing_api_key', array('requires DD_API_KEY')),
    'source' => array(array('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE' => 'secret-source'), 'invalid_source', array('configuration source is invalid')),
    'endpoint' => array(array('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE' => 'agentless', 'DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL' => 'ftp://secret-user:secret-password@example.com/path?key=secret-query'), 'invalid_endpoint', array('CDN base URL must')),
    'site' => array(array('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE' => 'agentless', 'DD_API_KEY' => 'secret-key', 'DD_SITE' => 'secret-site/path'), 'invalid_site', array('requires a valid DD_SITE')),
    'timings' => array(array('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE' => 'offline', 'DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS' => '0', 'DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS' => '-1', 'DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS' => '0'), 'disabled', array('using 30 seconds', 'using 5 seconds', 'using 10000 milliseconds')),
    'legacy' => array(array('DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED' => '0'), 'disabled', array('DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED is deprecated')),
);
$case = $cases[$caseName];
$log = tempnam(sys_get_temp_dir(), 'ffe-settings-');
$environment = ffeTestIsolatedSidecarEnvironment(array_merge($base, $case[0], array('DD_TRACE_LOG_FILE' => $log)));
$process = null;
$pipes = array();
try {
    $process = ffeTestProcess(__DIR__ . '/agentless_settings_app.php', $pipes, $environment);
    $stdout = $stderr = '';
    $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 60 : (PHP_OS === 'WINNT' ? 45 : 15));
    do {
        $stdout .= ffeTestProcessRead($pipes[1]);
        $stderr .= ffeTestProcessRead($pipes[2]);
        if (strpos($stdout, "fixture_child_ready\n") !== false) break;
        $status = proc_get_status($process);
        if (!$status['running']) throw new RuntimeException($caseName . ' child exited during startup: ' . $stdout . $stderr);
        usleep(10000);
    } while (microtime(true) < $deadline);
    if (strpos($stdout, "fixture_child_ready\n") === false) throw new RuntimeException($caseName . ' child startup timed out: ' . $stdout . $stderr);
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
    $stderr .= ffeTestProcessRead($pipes[2]);
    if ($status['running'] || $status['exitcode'] !== 0) throw new RuntimeException($caseName . ' failed: ' . $stdout . $stderr);
    $result = json_decode(str_replace("fixture_child_ready\n", '', $stdout), true);
    if (!is_array($result) || $result['state']['reason'] !== $case[1] || $result['value'] !== 'fallback') throw new RuntimeException($caseName . ' wrong result: ' . $stdout);
    if ($case[1] !== 'disabled' && $result['error'] !== $result['state']['errorMessage']) throw new RuntimeException('caller lost the failure reason');
    $diagnostics = file_get_contents($log);
    foreach ($case[2] as $message) {
        if (substr_count($diagnostics, $message) !== 1) throw new RuntimeException($caseName . ' diagnostic missing or repeated: ' . $diagnostics);
    }
    if (strpos($diagnostics . $stdout . $stderr, 'secret-') !== false) throw new RuntimeException('configuration secret leaked');
    echo $caseName, "=ok\n";
} finally {
    if (is_resource($process)) {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        ffeTestProcessTerminate($process);
        proc_close($process);
    }
    @unlink($log);
}
