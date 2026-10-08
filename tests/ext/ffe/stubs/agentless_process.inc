<?php
putenv('PHP_FFE_PROCESS_SCENARIO=' . $scenario);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) throw new RuntimeException($error);
$address = stream_socket_get_name($server, false);
putenv('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL=http://' . $address . '/configuration');
$command = 'exec ' . escapeshellarg(getenv('TEST_PHP_EXECUTABLE')) . ' '
    . getenv('TEST_PHP_EXTRA_ARGS') . ' ' . escapeshellarg(__DIR__ . '/agentless_process_app.php');
$process = proc_open($command, array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')), $pipes);
if (!is_resource($process)) throw new RuntimeException('cannot launch agentless PHP');
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$stdout = $stderr = '';
$requests = 0;
$heldConnections = array();
$exitCode = null;
$running = true;
$start = microtime(true);
try {
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
        stream_set_timeout($connection, 1);
        while (($line = fgets($connection)) !== false && trim($line) !== '') {}
        $requests++;
        if ($scenario === 'shutdown') {
            $heldConnections[] = $connection;
            continue;
        }
        if ($scenario === 'recovery' && $requests === 1) {
            usleep(1200000);
            $code = '503 Service Unavailable';
            $body = '';
        } else {
            $stdout .= stream_get_contents($pipes[1]);
            $forked = strpos($stdout, 'parent_forked') !== false && strpos($stdout, 'child_forked') !== false;
            $value = $scenario === 'fork' && $forked ? 'green' : 'blue';
            $code = '200 OK';
            $body = json_encode(array(
                'createdAt' => '2026-05-22T00:00:00Z', 'environment' => array('name' => 'test'),
                'flags' => array('flag' => array(
                    'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
                    'variations' => array('on' => array('key' => 'on', 'value' => $value)),
                    'allocations' => array(array('key' => 'allocation', 'rules' => array(), 'doLog' => false,
                        'splits' => array(array('variationKey' => 'on', 'shards' => array())))),
                )),
            ));
        }
        fwrite($connection, "HTTP/1.1 $code\r\nContent-Type: application/json\r\nContent-Length: "
            . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($connection);
    } while (microtime(true) - $start < 10);
    $elapsed = microtime(true) - $start;
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    if ($exitCode !== 0) throw new RuntimeException('child failed or timed out: ' . $stdout . ' ' . $stderr);
    if ($requests === 0) throw new RuntimeException('no configuration request');
    if ($scenario === 'shutdown' && $elapsed > 3) throw new RuntimeException('shutdown waited on HTTP timeout');
    // Fork output is intentionally concurrent; compare the complete set.
    $lines = explode("\n", trim($stdout));
    sort($lines);
    echo implode("\n", $lines), "\n";
    if ($scenario === 'shutdown') echo "shutdown_cancelled_inflight_request\n";
} finally {
    if ($running) proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    foreach ($heldConnections as $connection) fclose($connection);
    fclose($server);
}
