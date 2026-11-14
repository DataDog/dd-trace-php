<?php

require_once __DIR__ . '/ffe_process.inc';

// Prints the DD-Service header of the CDN requests from one PHP process.
function ffeTestAgentlessServiceHeader($name, $service, $otelService)
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$server) throw new RuntimeException($error);
    $environment = getenv();
    foreach (array('DD_FEATURE_FLAGS_ENABLED', 'DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED',
        'DD_SERVICE', 'OTEL_SERVICE_NAME') as $key) unset($environment[$key]);
    if ($service !== null) $environment['DD_SERVICE'] = $service;
    if ($otelService !== null) $environment['OTEL_SERVICE_NAME'] = $otelService;
    $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE'] = 'agentless';
    $environment['DD_TRACE_SIDECAR_CONNECTION_MODE'] = 'subprocess';
    $environment['_DD_DEBUG_SIDECAR_IPC_MODE'] = 'instance_per_process';
    $environment['_DD_DEBUG_SIDECAR_IDLE_LINGER_TIME_SECS'] = '1';
    $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL'] =
        'http://' . stream_socket_get_name($server, false) . '/configuration';
    $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS'] = '1';
    $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS'] = '1';
    $environment['DD_API_KEY'] = 'local-fixture-must-not-receive-this';
    $environment['NO_PROXY'] = '127.0.0.1,localhost';
    $process = ffeTestProcess(__DIR__ . '/agentless_service_header_app.php', $pipes, $environment);
    $stdout = $stderr = '';
    $values = array();
    $running = true;
    $exitCode = null;
    $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 60 : 15);
    $configuration = array(
        'createdAt' => '2026-05-22T00:00:00Z', 'format' => 'SERVER',
        'environment' => array('name' => 'test'), 'flags' => new stdClass(),
    );
    $body = json_encode(array('data' => array('type' => 'universal-flag-configuration', 'attributes' => $configuration)));
    $pump = function () use ($process, $pipes, &$stdout, &$stderr, &$running, &$exitCode, $deadline) {
        if (microtime(true) > $deadline) throw new RuntimeException('timed out: ' . $stdout . ' ' . $stderr);
        $stdout .= ffeTestProcessRead($pipes[1]);
        $stderr .= ffeTestProcessRead($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $running = false;
            if ($exitCode === null) $exitCode = $status['exitcode'];
        }
    };
    try {
        // Two polls show that every request sends the same header.
        while (count($values) < 2) {
            $pump();
            if (!$running) throw new RuntimeException('child exited: ' . $stdout . ' ' . $stderr);
            if (!$connection = @stream_socket_accept($server, 0.1)) continue;
            stream_set_timeout($connection, 2);
            try {
                $route = trim((string) fgets($connection));
                $headers = array();
                while (($line = fgets($connection)) !== false && trim($line) !== '') {
                    $pair = explode(':', $line, 2);
                    if (count($pair) === 2) $headers[strtolower($pair[0])] = trim($pair[1]);
                }
                if ($route !== 'GET /configuration HTTP/1.1') throw new RuntimeException('unexpected CDN request: ' . $route);
                $values[] = isset($headers['dd-service']) ? $headers['dd-service'] : '(none)';
                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                    . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
            } finally {
                fclose($connection);
            }
        }
        fwrite($pipes[0], "finish\n");
        while ($running) $pump();
        if ($exitCode !== 0) throw new RuntimeException('child failed: ' . $stdout . ' ' . $stderr);
        echo $name, '=', implode(',', array_unique($values)), "\n";
    } finally {
        if ($running) proc_terminate($process);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
        fclose($server);
    }
}
