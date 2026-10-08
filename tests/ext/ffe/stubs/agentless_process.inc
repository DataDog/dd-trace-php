<?php
require_once __DIR__ . '/ffe_process.inc';
$instrumented = getenv('USE_ZEND_ALLOC') === '0';
// Keep the no-renewal assertion decisive: ten renewed 2s waits exceed 3s.
if ($instrumented && $scenario !== 'fork') {
    putenv('DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS=2000');
}
putenv('PHP_FFE_PROCESS_SCENARIO=' . $scenario);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) throw new RuntimeException($error);
$address = stream_socket_get_name($server, false);
putenv('DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL=http://' . $address . '/configuration');
$process = ffeTestProcess(__DIR__ . '/agentless_process_app.php', $pipes);
if (!is_resource($process)) throw new RuntimeException('cannot launch agentless PHP');
if ($scenario !== 'shutdown') fclose($pipes[0]);
$stdout = $stderr = '';
$requests = 0;
$heldConnections = array();
$exitCode = null;
$running = true;
$start = microtime(true);
$readyAt = null;
$shutdownStart = null;
try {
    do {
        $stdout .= ffeTestProcessRead($pipes[1]);
        $stderr .= ffeTestProcessRead($pipes[2]);
        if ($readyAt === null && strpos($stdout, "fixture_child_ready\n") !== false) $readyAt = microtime(true);
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
            if ($shutdownStart === null) {
                // Release the child only once an HTTP request is in flight.
                // Worker scheduling may outlast its initialization budget.
                $shutdownStart = microtime(true);
                fwrite($pipes[0], "request_received\n");
                fclose($pipes[0]);
            }
            continue;
        }
        if ($scenario === 'recovery' && $requests === 1) {
            // Do not race first-use initialization or the ten nonwaiting calls.
            // Release the failed response only after the child observed both.
            $failureDeadline = microtime(true) + ($instrumented ? 15 : 3);
            do {
                $stdout .= ffeTestProcessRead($pipes[1]);
                if (strpos($stdout, "later_evaluations_do_not_wait\n") !== false) break;
                usleep(10000);
            } while (microtime(true) < $failureDeadline);
            if (strpos($stdout, "later_evaluations_do_not_wait\n") === false) {
                throw new RuntimeException('child did not finish deadline assertions');
            }
            $code = '503 Service Unavailable';
            $body = '';
        } else {
            $stdout .= ffeTestProcessRead($pipes[1]);
            $forked = strpos($stdout, 'parent_forked') !== false && strpos($stdout, 'child_forked') !== false;
            $value = $scenario === 'fork' && $forked ? 'green' : 'blue';
            $code = '200 OK';
            $configuration = array(
                'createdAt' => '2026-05-22T00:00:00Z', 'environment' => array('name' => 'test'),
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
        }
        fwrite($connection, "HTTP/1.1 $code\r\nContent-Type: application/json\r\nContent-Length: "
            . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        fclose($connection);
    } while (microtime(true) - ($readyAt === null ? $start : $readyAt) < ($readyAt === null ? 60 : ($instrumented ? 45 : 20)));
    $stdout .= ffeTestProcessRead($pipes[1]);
    $stderr .= ffeTestProcessRead($pipes[2]);
    if ($exitCode !== 0) throw new RuntimeException('child failed or timed out: ' . $stdout . ' ' . $stderr);
    if ($requests === 0) throw new RuntimeException('no configuration request');
    if ($scenario === 'shutdown' && microtime(true) - $shutdownStart > ($instrumented ? 10 : 3)) {
        throw new RuntimeException('shutdown waited on HTTP timeout');
    }
    // Fork output is intentionally concurrent; compare the complete set.
    $lines = explode("\n", trim(str_replace("fixture_child_ready\n", '', $stdout)));
    sort($lines);
    echo implode("\n", $lines), "\n";
    if ($scenario === 'shutdown') echo "shutdown_cancelled_inflight_request\n";
} finally {
    if ($running) proc_terminate($process);
    if (is_resource($pipes[0])) fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    foreach ($heldConnections as $connection) fclose($connection);
    fclose($server);
}
