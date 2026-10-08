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
$phase = 0;
$expectedEtag = null;
try {
    $startedAt = microtime(true);
    $readyAt = null;
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        if ($readyAt === null && strpos($stdout, "fixture_child_ready\n") !== false) $readyAt = microtime(true);
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
        $requests[] = array($route, $headers, $expectedEtag);
        // Keep stale/304 states observable even when sanitizers preempt the
        // child for an entire poll interval. The child must acknowledge each.
        if ($phase === 3 && strpos($stdout, "fixture_stale_observed\n") !== false) $phase = 4;
        if ($phase === 4 && strpos($stdout, "fixture_304_observed\n") !== false) $phase = 5;
        $notModified = in_array($phase, array(1, 4, 6), true);
        $code = $notModified ? '304 Not Modified' : '200 OK';
        $etag = $phase === 3 ? '"bad"' : ($phase < 5 ? '"blue"' : '"green"');
        $value = $phase < 5 ? 'blue' : 'green';
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
        if ($phase === 3) $body = '{malformed';
        fwrite($connection, "HTTP/1.1 $code\r\nContent-Type: application/json\r\nETag: $etag\r\nContent-Length: "
            . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($connection);
        if ($phase === 0) { $expectedEtag = '"blue"'; $phase = 1; }
        elseif ($phase === 1) $phase = 3;
        elseif ($phase === 5) { $expectedEtag = '"green"'; $phase = 6; }
    } while (microtime(true) - ($readyAt === null ? $startedAt : $readyAt) < ($readyAt === null ? 60 : 35));
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    if ($exitCode !== 0) throw new RuntimeException('child failed or timed out: ' . $stdout . ' ' . $stderr);
    echo str_replace(array("fixture_child_ready\n", "fixture_stale_observed\n", "fixture_304_observed\n"), '', $stdout);
    if (count($requests) < 5) throw new RuntimeException('polling did not recover');
    $protocol = true;
    foreach ($requests as $i => $request) {
        list($route, $headers, $expectedEtag) = $request;
        $protocol = $protocol && $route === 'GET /configuration?fixture=1 HTTP/1.1'
            && !isset($headers['dd-api-key'])
            && $headers['dd-client-library-language'] === 'php'
            && $headers['dd-client-library-version'] === phpversion('ddtrace')
            && $headers['dd-internal-untraced-request'] === '1';
        if ($expectedEtag === null) $protocol = $protocol && !isset($headers['if-none-match']);
        else $protocol = $protocol && $headers['if-none-match'] === $expectedEtag;
    }
    echo 'request_protocol=', json_encode($protocol), "\n";
} finally {
    if ($running) proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    fclose($server);
}
