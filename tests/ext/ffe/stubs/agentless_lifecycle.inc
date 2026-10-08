<?php
putenv('PHP_FFE_AGENTLESS_OPENFEATURE=' . ($openFeature ? '1' : '0'));
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) throw new RuntimeException($error);
$address = stream_socket_get_name($server, false);
putenv('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL=http://' . $address . '/configuration?fixture=1');
$command = (PHP_OS === 'WINNT' ? '' : 'exec ') . escapeshellarg(getenv('TEST_PHP_EXECUTABLE')) . ' '
    . getenv('TEST_PHP_EXTRA_ARGS') . ' ' . escapeshellarg(__DIR__ . '/agentless_lifecycle_app.php');
$process = proc_open($command, array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')), $pipes);
if (!is_resource($process)) throw new RuntimeException('cannot launch agentless PHP');
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$stdout = $stderr = '';
$requests = array();
$exitCode = null;
$running = true;
try {
    $deadline = microtime(true) + 12;
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $running = false;
            $exitCode = $status['exitcode'];
            break;
        }
        $connection = @stream_socket_accept($server, 0.1);
        if (!$connection) continue;
        stream_set_timeout($connection, 2);
        $route = trim(fgets($connection));
        $headers = array();
        while (($line = fgets($connection)) !== false && trim($line) !== '') {
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) $headers[strtolower($pair[0])] = trim($pair[1]);
        }
        $requests[] = array($route, $headers);
        $index = count($requests);
        $notModified = $index === 2 || $index === 4;
        $code = $notModified ? '304 Not Modified' : '200 OK';
        $etag = $index === 3 ? '"bad"' : ($index <= 4 ? '"blue"' : '"green"');
        $value = $index <= 4 ? 'blue' : 'green';
        $configuration = array(
            'createdAt' => '2026-05-22T00:00:00Z',
            'environment' => array('name' => 'test'),
            'flags' => array('flag' => array(
                'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
                'variations' => array('on' => array('key' => 'on', 'value' => $value)),
                'allocations' => array(array('key' => 'allocation', 'rules' => array(), 'doLog' => false,
                    'splits' => array(array('variationKey' => 'on', 'shards' => array())))),
            )),
        );
        $configuration['format'] = 'SERVER';
        $body = json_encode(array('data' => array(
            'type' => 'universal-flag-configuration', 'attributes' => $configuration,
        )));
        if ($notModified) $body = '';
        if ($index === 3) $body = '{malformed';
        fwrite($connection, "HTTP/1.1 $code\r\nContent-Type: application/json\r\nETag: $etag\r\nContent-Length: "
            . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($connection);
    } while (microtime(true) < $deadline);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    if ($exitCode !== 0) throw new RuntimeException('child failed or timed out: ' . $stdout . ' ' . $stderr);
    echo $stdout;
    if (count($requests) < 5) throw new RuntimeException('polling did not recover');
    $protocol = true;
    foreach ($requests as $i => $request) {
        list($route, $headers) = $request;
        $protocol = $protocol && $route === 'GET /configuration?fixture=1 HTTP/1.1'
            && !isset($headers['dd-api-key'])
            && $headers['dd-client-library-language'] === 'php'
            && $headers['dd-client-library-version'] === phpversion('ddtrace')
            && $headers['dd-internal-untraced-request'] === '1';
        if ($i === 0) $protocol = $protocol && !isset($headers['if-none-match']);
        if ($i > 0 && $i < 5) $protocol = $protocol && $headers['if-none-match'] === '"blue"';
    }
    echo 'request_protocol=', json_encode($protocol), "\n";
} finally {
    if ($running) proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    fclose($server);
}
