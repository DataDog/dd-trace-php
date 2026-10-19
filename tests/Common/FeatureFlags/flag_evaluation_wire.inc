<?php
require_once dirname(__DIR__, 2) . '/ext/ffe/stubs/ffe_process.inc';
putenv('PHP_FFE_WIRE_OPENFEATURE=' . ($openFeature ? '1' : '0'));
// The PHP connector forwards this setting to the sidecar subprocess.
putenv('DD_TRACE_LOG_LEVEL=trace');
$diagnosticLog = tempnam(sys_get_temp_dir(), 'php-ffe-diagnostics-');
if ($diagnosticLog === false) throw new RuntimeException('cannot create diagnostic log');
putenv('DD_TRACE_LOG_FILE=' . $diagnosticLog);
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
            $captures[] = array($requestLine, $headers, json_decode($body, true), $body);
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
    $protectedBytesAbsent = true;
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
            $protectedBytesAbsent = $protectedBytesAbsent
                && strpos($capture[3], 'ffe-protected-pii-canary@example.test') === false
                && strpos($capture[3], 'ffe-context-pii-canary@example.test') === false;
            if (strpos($capture[3], 'ffe-error-pii-canary@example.test') !== false) {
                throw new RuntimeException('raw error text reached the payload');
            }
            $context = $payload['context'];
            ksort($context);
            if ($context !== array('env' => 'test', 'service' => 'php-ffe-wire', 'version' => 'wire-version')) {
                throw new RuntimeException('incorrect service context: ' . json_encode($payload['context']));
            }
            foreach ($payload['flagEvaluations'] as $row) {
                $key = $row['flag']['key'];
                if (isset($rows[$key])) {
                    $rows[$key]['evaluation_count'] += $row['evaluation_count'];
                    $rows[$key]['timestamp'] = max($rows[$key]['timestamp'], $row['timestamp']);
                    $rows[$key]['first_evaluation'] = min($rows[$key]['first_evaluation'], $row['first_evaluation']);
                    $rows[$key]['last_evaluation'] = max($rows[$key]['last_evaluation'], $row['last_evaluation']);
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
    echo 'protected_raw_bytes_absent='; var_dump($protectedBytesAbsent);
    echo 'evaluation_times_preserved=';
    var_dump($rows['timestamped']['evaluation_count'] === 2
        && $rows['timestamped']['timestamp'] === 1700000000456
        && $rows['timestamped']['first_evaluation'] === 1700000000123
        && $rows['timestamped']['last_evaluation'] === 1700000000456);
    echo 'identity_omissions_preserve_evaluations=';
    var_dump(!isset($rows['coerced']['targeting_key']) && $rows['coerced']['evaluation_count'] === 1
        && !isset($rows['missing']['targeting_key']) && $rows['missing']['evaluation_count'] === 1
        && !isset($rows['malformed']['targeting_key']) && $rows['malformed']['evaluation_count'] === 1
        && $rows['empty']['targeting_key'] === '' && $rows['empty']['evaluation_count'] === 1);
    $bounded = $rows['bounded']['context']['evaluation'];
    echo 'first_256_inspected=';
    var_dump(count($bounded) === 255 && !isset($bounded['rejected'])
        && !isset($bounded['beyond-limit']) && $bounded['field-1'] === 1 && $bounded['field-255'] === 255);
    $unicode = $rows['unicode']['context']['evaluation'];
    $expected = array(str_repeat('é', 256) => str_repeat('😀', 256),
        'bool' => false, 'integer' => -42, 'float' => 1.5,
        0 => 'zero-key', -1 => 'negative-key', 'empty' => '');
    ksort($unicode, SORT_STRING);
    ksort($expected, SORT_STRING);
    echo 'unicode_and_scalar_boundaries='; var_dump($unicode === $expected);
    echo 'owned_snapshot=';
    var_dump($rows['snapshot']['context']['evaluation'] === array('value' => 'before-submission'));
    echo 'invalid_context_keeps_observation=';
    var_dump($rows['all-invalid']['evaluation_count'] === 1 && $rows['all-invalid']['variant']['key'] === 'blue'
        && $rows['all-invalid']['targeting_key'] === 'subject' && !isset($rows['all-invalid']['context']['evaluation']));
    echo 'recovered_connection_preserves_privacy=';
    var_dump($rows['recovered']['evaluation_count'] === 1
        && $rows['recovered']['targeting_key'] === 'sha256_2234a4963a43ffe952df4e09b1fd0d36f8b49216e5cba4182ae4d01e67cf112a'
        && !isset($rows['recovered']['context']['evaluation']));
    echo 'consented_identity_and_safe_error=';
    var_dump($rows['consented-canary']['targeting_key'] === 'ffe-consented-pii-canary@example.test'
        && $rows['consented-canary']['context']['evaluation']['email'] === 'ffe-consented-context-canary@example.test'
        && $rows['consented-canary']['error']['message'] === 'GENERAL');
    if ($openFeature) {
        $terminalErrors = true;
        foreach (array('hook-before-error', 'hook-after-error') as $flag) {
            $row = $rows[$flag];
            $terminalErrors = $terminalErrors && $row['evaluation_count'] === 1
                && !isset($row['variant']) && !isset($row['allocation'])
                && $row['runtime_default_used'] === true && $row['error']['message'] === 'GENERAL'
                && $row['targeting_key'] === 'sha256_2234a4963a43ffe952df4e09b1fd0d36f8b49216e5cba4182ae4d01e67cf112a'
                && !isset($row['context']['evaluation']);
        }
        echo 'terminal_hook_errors='; var_dump($terminalErrors);
    }
    // TRACE logs can be large in CI. Inspect every byte without loading the
    // entire log (and a second concatenated copy) into the PHP process.
    $diagnostics = fopen($diagnosticLog, 'r');
    if ($diagnostics === false) throw new RuntimeException('cannot open diagnostic log');
    try {
        echo 'diagnostics_exercised=';
        var_dump(ffeTestStreamContains($diagnostics, 'FfeFlagEvaluationEvent')
            || strpos($stderr, 'FfeFlagEvaluationEvent') !== false);
        $piiAbsent = true;
        foreach (array('ffe-protected-pii-canary@example.test', 'ffe-context-pii-canary@example.test',
            'ffe-consented-pii-canary@example.test', 'ffe-consented-context-canary@example.test',
            'ffe-error-pii-canary@example.test') as $canary) {
            $piiAbsent = $piiAbsent && !ffeTestStreamContains($diagnostics, $canary)
                && strpos($stderr, $canary) === false;
        }
        echo 'diagnostics_pii_absent='; var_dump($piiAbsent);
    } finally {
        fclose($diagnostics);
    }
} finally {
    if ($exitedAt === null) proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    fclose($listener);
    @unlink($diagnosticLog);
}
?>
