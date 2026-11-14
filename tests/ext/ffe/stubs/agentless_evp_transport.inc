<?php
require __DIR__ . '/ffe_process.inc';
require __DIR__ . '/ffe_http_server.inc';
foreach ($modes as $mode) {
  foreach (array('false', 'true') as $sender) {
    $socket = sys_get_temp_dir() . '/ffe-evp-' . getmypid() . '.sock';
    $unix = $mode === 'trace-agentless-unix';
    $server = stream_socket_server($unix ? 'unix://' . $socket : 'tcp://127.0.0.1:0', $errno, $error);
    if (!$server) throw new RuntimeException($error);
    $environment = getenv();
    $environment['DD_FEATURE_FLAGS_ENABLED'] = 'true';
    $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE'] = $mode === 'remote-config' ? 'remote_config' : 'agentless';
    $environment['DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS'] = '1';
    $environment['DD_TRACE_AGENTLESS'] = strpos($mode, 'trace-agentless') === 0 ? 'true' : 'false';
    $environment['DD_TRACE_SIDECAR_TRACE_SENDER'] = $sender;
    $environment['PHP_FFE_TRANSPORT_FAILURE'] = strpos($mode, 'managed-') === 0 ? 'true' : 'false';
    $tokens = $mode === 'trace-agentless-token' ? array('transport-session-a', 'transport-session-b') : array('transport-session');
    $environment['DD_TRACE_AGENT_TEST_SESSION_TOKEN'] = $tokens[0];
    $environment['PHP_FFE_TRANSPORT_TOKENS'] = implode(',', $tokens);
    $environment['DD_TRACE_SIDECAR_CONNECTION_MODE'] = 'subprocess';
    $environment['_DD_DEBUG_SIDECAR_IPC_MODE'] = 'instance_per_process';
    $environment['_DD_DEBUG_SIDECAR_IDLE_LINGER_TIME_SECS'] = '1';
    $pathPrefix = $mode === 'trace-agentless-prefix' ? '/agent-prefix' : '';
    $environment['DD_TRACE_AGENT_URL'] = $unix ? 'unix://' . $socket : 'http://' . stream_socket_get_name($server, false) . $pathPrefix;
    $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL'] = strpos($mode, 'managed-') === 0 ? ''
        : ($unix ? 'http://127.0.0.1:1/configuration' : 'http://' . stream_socket_get_name($server, false) . '/configuration');
    if ($mode === 'trace-agentless-invalid') $environment['DD_TRACE_AGENT_URL'] = 'http://[';
    $environment['DD_API_KEY'] = in_array($mode, array('no-key', 'managed-no-key'), true) ? '' : 'must-not-reach-local-proxy';
    $environment['DD_SITE'] = ($mode !== 'agentless' && $mode !== 'remote-config' && $mode !== 'no-key' && $mode !== 'managed-no-key') ? 'bad/site' : 'datadoghq.com';
    $environment['NO_PROXY'] = '127.0.0.1,localhost';
    $process = ffeTestProcess(__DIR__ . '/agentless_evp_app.php', $pipes, $environment);
    $events = array();
    $expected = strpos($mode, 'managed-') === 0 ? array('flagevaluation') : array('exposures', 'flagevaluation');
    if ($mode === 'trace-agentless-invalid') $expected = array();
    $clients = array();
    $exitCode = null;
    $exitedAt = null;
    $lastCaptureAt = 0;
    $prefix = $mode === 'remote-config' ? '/evp_proxy/v2/api/v2/' : '/evp_proxy/v4/api/v2/';
    try {
        $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 60 : 15);
        do {
            if ($request = ffeTestHttpRequest($server, $clients)) {
                list($connection, $path, $headers, $body) = $request;
                try {
                    if (isset($headers['dd-api-key'])) throw new RuntimeException('credentials reached local proxy or custom CDN');
                    $response = '{}';
                    if ($path === $pathPrefix . '/info') {
                        $response = json_encode(array('endpoints' => $mode === 'remote-config' ? array('/evp_proxy/v2') : array('/evp_proxy/v4'),
                            'evp_proxy_allowed_headers' => array('DD-EVP-ORIGIN', 'DD-EVP-ORIGIN-VERSION')));
                    } elseif ($path === '/configuration') {
                        $response = json_encode(array('data' => array('type' => 'universal-flag-configuration',
                            'attributes' => json_decode(file_get_contents(__DIR__ . '/agentless_evp_config.json'), true))));
                    } elseif (strpos($path, $pathPrefix . '/evp_proxy/') === 0) {
                        $path = substr($path, strlen($pathPrefix));
                        if (strpos($path, $prefix) !== 0) throw new RuntimeException('wrong EVP route: ' . $path);
                        if ($headers['dd-evp-origin'] !== 'dd-trace-php' || $headers['dd-evp-origin-version'] !== phpversion('ddtrace')) {
                            throw new RuntimeException('wrong producer identity');
                        }
                        if (!isset($headers['x-datadog-test-session-token']) || !in_array($headers['x-datadog-test-session-token'], $tokens, true)) throw new RuntimeException('wrong test token');
                        $kind = substr($path, strlen($prefix));
                        if (!in_array($kind, $expected, true)) throw new RuntimeException('unexpected event stream: ' . $kind);
                        $payload = json_decode($body, true);
                        $field = $kind === 'exposures' ? 'exposures' : 'flagEvaluations';
                        if (!isset($payload[$field]) || count($payload[$field]) !== 1
                            || $payload[$field][0]['flag']['key'] !== 'transport-flag') {
                            throw new RuntimeException('unexpected event payload: ' . $body);
                        }
                        if (strpos($mode, 'managed-') === 0 && ($kind !== 'flagevaluation' || empty($payload[$field][0]['runtime_default_used']))) throw new RuntimeException('expected failed-evaluation count');
                        $subject = 'transport-user-' . $headers['x-datadog-test-session-token'];
                        $row = $payload[$field][0];
                        if ($kind === 'exposures' ? $row['subject']['id'] !== $subject
                            : $row['targeting_key'] !== 'sha256_' . hash('sha256', $subject)) {
                            throw new RuntimeException('event attributed to the wrong test session');
                        }
                        $eventKey = $kind . '/' . $headers['x-datadog-test-session-token'];
                        if (isset($events[$eventKey])) throw new RuntimeException('duplicate event batch');
                        $events[$eventKey] = true;
                        $lastCaptureAt = microtime(true);
                    }
                    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                        . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
                } finally {
                    fclose($connection);
                }
            }
            if ($exitedAt === null) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    $exitedAt = microtime(true);
                }
            }
            if ($exitCode !== null && $exitCode !== 0) break;
            // The sidecar can finish delivery after PHP exits. Keep receiving
            // both streams, then observe a short quiet period for duplicates.
            if ($exitedAt !== null && count($events) === count($expected) * count($tokens)
                && microtime(true) - max($exitedAt, $lastCaptureAt) > 0.5) break;
        } while (microtime(true) < $deadline);
        if ($exitCode !== 0) throw new RuntimeException('child failed: ' . ffeTestProcessRead($pipes[1]) . ffeTestProcessRead($pipes[2]));
        if (count($events) !== count($expected) * count($tokens)) {
            throw new RuntimeException($mode . ': missing event stream; received ' . implode(', ', array_keys($events))
                . '; child output: ' . ffeTestProcessRead($pipes[1]) . ffeTestProcessRead($pipes[2]));
        }
        echo $mode, '/', $sender, $mode === 'trace-agentless-invalid' ? ': invalid local endpoint handled'
            : ': expected streams delivered with PHP identity, token and no API key', "\n";
    } finally {
        if ($exitCode === null) proc_terminate($process);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
        foreach ($clients as $client) fclose($client['stream']);
        fclose($server);
        if ($unix) unlink($socket);
    }
  }
}
?>
