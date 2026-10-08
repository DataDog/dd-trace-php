<?php
require_once __DIR__ . '/ffe_process.inc';
putenv('PHP_FFE_WIRE_OPENFEATURE=' . ($openFeature ? '1' : '0'));
$direct = isset($direct) && $direct;
$tlsDir = null;
if ($direct) {
    require __DIR__ . '/evp_tls_fixture.inc';
    $tlsDir = ffeWireTlsFixture();
    putenv('SSL_CERT_FILE=' . $tlsDir . '/ca.pem');
}
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) {
    throw new RuntimeException($error);
}
$agentless = isset($agentless) && $agentless;
$evpVersion = isset($evpVersion) ? $evpVersion : 'v2';
$prefix = $agentless ? '/customer/proxy' : '';
$serverUrl = 'http://' . stream_socket_get_name($listener, false);
putenv('DD_TRACE_AGENT_URL=' . $serverUrl . $prefix);
if ($agentless) {
    putenv('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL=' . $serverUrl . '/configuration');
}
if ($direct) {
    // run-tests.php exports REQUEST_METHOD even for CLI tests. Remove that
    // synthetic CGI marker from this CLI child; the HTTP client's CGI guard
    // intentionally ignores environment proxies whenever it is present.
    putenv('REQUEST_METHOD');
    putenv('HTTPS_PROXY=' . $serverUrl);
    putenv('https_proxy=' . $serverUrl);
    putenv('HTTP_PROXY=');
    putenv('http_proxy=');
    putenv('ALL_PROXY=');
    putenv('all_proxy=');
    putenv('NO_PROXY=127.0.0.1,localhost');
    putenv('no_proxy=127.0.0.1,localhost');
}
$process = ffeTestProcess(__DIR__ . '/flag_evaluation_wire_app.php', $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('failed to launch evaluation process');
}
fclose($pipes[0]);
$captures = array();
$requests = array();
$stdout = $stderr = '';
$exitedAt = null;
$exitCode = null;
$lastCaptureAt = 0;
try {
    $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 30 : 10);
    do {
        $stdout .= ffeTestProcessRead($pipes[1]);
        $stderr .= ffeTestProcessRead($pipes[2]);
        if ($exitedAt === null) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitedAt = microtime(true);
                $exitCode = $status['exitcode'];
            }
        }
        // Observe the whole child lifetime plus a quiet interval. Stopping at
        // the first batch would hide duplicate counts sent in later batches.
        if ($exitedAt !== null && microtime(true) - max($exitedAt, $lastCaptureAt) > 0.5) break;
        $connection = @stream_socket_accept($listener, 0.1);
        if (!$connection) {
            continue;
        }
        stream_set_timeout($connection, 2);
        $requestLine = trim(fgets($connection));
        $requests[] = $requestLine;
        $headers = array();
        while (($line = fgets($connection)) !== false && trim($line) !== '') {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower($parts[0])] = trim($parts[1]);
            }
        }
        $isDirect = strpos($requestLine, 'CONNECT ') === 0;
        if ($isDirect) {
            if (!$direct || $requestLine !== 'CONNECT event-platform-intake.example.invalid:443 HTTP/1.1'
                || isset($headers['dd-api-key'])) {
                throw new RuntimeException('unexpected CONNECT destination or credentials');
            }
            fwrite($connection, "HTTP/1.1 200 Connection Established\r\n\r\n");
            stream_context_set_option($connection, 'ssl', 'local_cert', $tlsDir . '/server.pem');
            stream_context_set_option($connection, 'ssl', 'verify_peer', false);
            if (stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                throw new RuntimeException('TLS handshake failed');
            }
            $requestLine = trim(fgets($connection));
            $requests[] = $requestLine;
            $headers = array();
            while (($line = fgets($connection)) !== false && trim($line) !== '') {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower($parts[0])] = trim($parts[1]);
            }
        }
        $length = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
        $body = '';
        while (strlen($body) < $length && !feof($connection)) {
            $chunk = fread($connection, $length - strlen($body));
            if ($chunk === false || $chunk === '') break;
            $body .= $chunk;
        }
        $isEvp = strpos($requestLine, '/evp_proxy/') !== false || $isDirect;
        if ($isEvp) {
            $expectedRoute = $direct ? 'POST /api/v2/' : 'POST ' . $prefix . '/evp_proxy/' . $evpVersion . '/api/v2/';
            if ($isDirect !== $direct || strpos($requestLine, $expectedRoute) !== 0) {
                throw new RuntimeException('wrong EVP route: ' . $requestLine);
            }
            $captures[] = array($requestLine, $headers, json_decode($body, true));
            $lastCaptureAt = microtime(true);
        }
        $response = '{}';
        if ($agentless && strpos($requestLine, 'GET ' . $prefix . '/info ') === 0) {
            $response = json_encode(array(
                'endpoints' => $direct ? array() : ($evpVersion === 'v4' ? array('/evp_proxy/v2', '/evp_proxy/v4') : array('/evp_proxy/v2')),
                'evp_proxy_allowed_headers' => array('DD-EVP-ORIGIN', 'DD-EVP-ORIGIN-VERSION'),
            ));
        }
        $responseStatus = $isEvp ? '202 Accepted' : '200 OK';
        fwrite($connection, "HTTP/1.1 $responseStatus\r\nContent-Type: application/json\r\nContent-Length: "
            . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
        fclose($connection);
    } while (microtime(true) < $deadline);
    $stdout .= ffeTestProcessRead($pipes[1]);
    $stderr .= ffeTestProcessRead($pipes[2]);
    if (!$captures) {
        throw new RuntimeException('no EVP capture: ' . json_encode($requests) . ' ' . $stdout . ' ' . $stderr);
    }
    if ($exitCode !== 0) {
        throw new RuntimeException('evaluation process failed or timed out: ' . $stdout . ' ' . $stderr);
    }
    $rows = array();
    $exposureCount = 0;
    $identity = true;
    foreach ($captures as $capture) {
        list($request, $headers, $payload) = $capture;
        $routeHeaders = $direct
            ? isset($headers['dd-api-key']) && $headers['dd-api-key'] === 'local-test-key-must-not-reach-agent'
                && !isset($headers['x-datadog-evp-subdomain'])
                && $headers['host'] === 'event-platform-intake.example.invalid'
            : !isset($headers['dd-api-key']) && isset($headers['x-datadog-evp-subdomain'])
                && $headers['x-datadog-evp-subdomain'] === 'event-platform-intake';
        $identity = $identity && $routeHeaders
            && isset($headers['dd-evp-origin']) && $headers['dd-evp-origin'] === 'dd-trace-php'
            && isset($headers['dd-evp-origin-version']) && $headers['dd-evp-origin-version'] === phpversion('ddtrace');
        if (!$identity) {
            echo 'identity_headers=', json_encode(array_intersect_key($headers,
                array('dd-evp-origin' => true, 'dd-evp-origin-version' => true))), "\n";
        }
        if (strpos($request, '/flagevaluation ') !== false) {
            $context = $payload['context'];
            ksort($context);
            if ($context !== array('env' => 'test', 'service' => 'php-ffe-wire', 'version' => 'wire-version')) {
                throw new RuntimeException('incorrect service context: ' . json_encode($payload['context']));
            }
            foreach ($payload['flagEvaluations'] as $row) {
                $key = $row['flag']['key'];
                if (isset($rows[$key])) {
                    $rows[$key]['evaluation_count'] += $row['evaluation_count'];
                } else {
                    $rows[$key] = $row;
                }
            }
        } else {
            $exposureCount += count($payload['exposures']);
        }
    }
    echo 'service=php-ffe-wire', "\n";
    echo 'identity='; var_dump($identity);
    echo 'exposures='; var_dump($exposureCount);
    echo 'consented_count='; var_dump($rows['consented']['evaluation_count']);
    echo 'consented_subject='; var_dump($rows['consented']['targeting_key']);
    echo 'consented_context='; var_dump($rows['consented']['context']['evaluation']);
    echo 'protected_count='; var_dump($rows['protected']['evaluation_count']);
    echo 'protected_subject='; var_dump($rows['protected']['targeting_key']);
    echo 'protected_context_absent='; var_dump(!isset($rows['protected']['context']['evaluation']));
} finally {
    if ($exitedAt === null) proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    fclose($listener);
    if ($tlsDir !== null) {
        foreach (glob($tlsDir . '/*') as $file) unlink($file);
        rmdir($tlsDir);
    }
}
?>
