--TEST--
FFE agentless discovers a shared local EVP route while Remote Config keeps EVP v2
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: subprocess sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_AGENT_TIMEOUT=5000
DD_SERVICE=php-ffe-transport
DD_FLAGGING_EVALUATION_COUNTS_ENABLED=1
--FILE--
<?php
require __DIR__ . '/stubs/ffe_process.inc';
foreach (array('agentless', 'no-key', 'invalid-site', 'remote-config') as $mode) {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$server) throw new RuntimeException($error);
    $environment = getenv();
    $environment['DD_FEATURE_FLAGS_ENABLED'] = 'true';
    $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE'] = $mode === 'remote-config' ? 'remote_config' : 'agentless';
    $environment['DD_TRACE_AGENTLESS'] = 'false';
    $environment['DD_TRACE_SIDECAR_CONNECTION_MODE'] = 'subprocess';
    $environment['_DD_DEBUG_SIDECAR_IPC_MODE'] = 'instance_per_process';
    $environment['_DD_DEBUG_SIDECAR_IDLE_LINGER_TIME_SECS'] = '1';
    $environment['DD_TRACE_AGENT_URL'] = 'http://' . stream_socket_get_name($server, false);
    $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL'] = $environment['DD_TRACE_AGENT_URL'] . '/configuration';
    $environment['DD_API_KEY'] = $mode === 'no-key' ? '' : 'must-not-reach-local-proxy';
    $environment['DD_SITE'] = $mode === 'invalid-site' ? 'bad/site' : 'datadoghq.com';
    $environment['NO_PROXY'] = '127.0.0.1,localhost';
    $process = ffeTestProcess(__DIR__ . '/stubs/agentless_evp_app.php', $pipes, $environment);
    $events = array();
    $exitCode = null;
    $prefix = $mode === 'remote-config' ? '/evp_proxy/v2/api/v2/' : '/evp_proxy/v4/api/v2/';
    try {
        $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 60 : 15);
        do {
            if ($connection = @stream_socket_accept($server, 0.05)) {
                stream_set_timeout($connection, 2);
                try {
                    $request = explode(' ', trim((string)fgets($connection)));
                    $path = $request[1];
                    $headers = array();
                    while (($line = fgets($connection)) !== false && trim($line) !== '') {
                        $pair = explode(':', $line, 2);
                        if (count($pair) === 2) $headers[strtolower($pair[0])] = trim($pair[1]);
                    }
                    if (isset($headers['dd-api-key'])) throw new RuntimeException('credentials reached local proxy or custom CDN');
                    $length = isset($headers['content-length']) ? (int)$headers['content-length'] : 0;
                    $body = '';
                    while (strlen($body) < $length && !feof($connection)) {
                        $chunk = fread($connection, $length - strlen($body));
                        if ($chunk === false || $chunk === '') break;
                        $body .= $chunk;
                    }
                    $response = '{}';
                    if ($path === '/info') {
                        $response = json_encode(array('endpoints' => array('/evp_proxy/v2', '/evp_proxy/v4'),
                            'evp_proxy_allowed_headers' => array('DD-EVP-ORIGIN', 'DD-EVP-ORIGIN-VERSION')));
                    } elseif ($path === '/configuration') {
                        $response = json_encode(array('data' => array('type' => 'universal-flag-configuration',
                            'attributes' => json_decode(file_get_contents(__DIR__ . '/stubs/agentless_evp_config.json'), true))));
                    } elseif (strpos($path, '/evp_proxy/') === 0) {
                        if (strpos($path, $prefix) !== 0) throw new RuntimeException('wrong EVP route: ' . $path);
                        if ($headers['dd-evp-origin'] !== 'dd-trace-php' || $headers['dd-evp-origin-version'] !== phpversion('ddtrace')) {
                            throw new RuntimeException('wrong producer identity');
                        }
                        $kind = substr($path, strlen($prefix));
                        $payload = json_decode($body, true);
                        $field = $kind === 'exposures' ? 'exposures' : 'flagEvaluations';
                        if (!isset($payload[$field]) || count($payload[$field]) !== 1
                            || $payload[$field][0]['flag']['key'] !== 'transport-flag') {
                            throw new RuntimeException('unexpected event payload: ' . $body);
                        }
                        if (isset($events[$kind])) throw new RuntimeException('duplicate event batch');
                        $events[$kind] = true;
                    }
                    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                        . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
                } finally {
                    fclose($connection);
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) { $exitCode = $status['exitcode']; break; }
        } while (microtime(true) < $deadline);
        if ($exitCode !== 0) throw new RuntimeException('child failed: ' . ffeTestProcessRead($pipes[2]));
        if (!isset($events['exposures'], $events['flagevaluation'])) throw new RuntimeException('missing event stream');
        echo $mode, ': both streams delivered with PHP identity and no API key', "\n";
    } finally {
        if ($exitCode === null) proc_terminate($process);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
        fclose($server);
    }
}
?>
--EXPECT--
agentless: both streams delivered with PHP identity and no API key
no-key: both streams delivered with PHP identity and no API key
invalid-site: both streams delivered with PHP identity and no API key
remote-config: both streams delivered with PHP identity and no API key
