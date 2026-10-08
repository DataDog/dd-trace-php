<?php
require_once __DIR__ . '/ffe_process.inc';
putenv('PHP_FFE_WIRE_OPENFEATURE=' . ($openFeature ? '1' : '0'));
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) {
    throw new RuntimeException($error);
}
putenv('DD_TRACE_AGENT_URL=http://' . stream_socket_get_name($listener, false));
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
        $length = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
        $body = '';
        while (strlen($body) < $length && !feof($connection)) {
            $chunk = fread($connection, $length - strlen($body));
            if ($chunk === false || $chunk === '') break;
            $body .= $chunk;
        }
        if (strpos($requestLine, '/evp_proxy/v2/api/v2/') !== false) {
            $captures[] = array($requestLine, $headers, json_decode($body, true));
            $lastCaptureAt = microtime(true);
        }
        $response = '{}';
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n" . $response);
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
        $identity = $identity && !isset($headers['dd-api-key'])
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
}
?>
