<?php

require_once __DIR__ . '/ffe_process.inc';

function ffeTestAgentlessActivation($name, $source, $enabled, $fetchBefore, $fetchAfter, $legacy = null)
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$server) throw new RuntimeException($error);
    $environment = getenv();
    foreach (array('DD_FEATURE_FLAGS_ENABLED', 'DD_FEATURE_FLAGS_CONFIGURATION_SOURCE',
        'DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED') as $key) unset($environment[$key]);
    if ($source !== null) $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE'] = $source;
    if ($enabled !== null) $environment['DD_FEATURE_FLAGS_ENABLED'] = $enabled;
    if ($legacy !== null) $environment['DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED'] = $legacy;
    $environment['DD_TRACE_SIDECAR_CONNECTION_MODE'] = 'subprocess';
    $environment['_DD_DEBUG_SIDECAR_IPC_MODE'] = 'instance_per_process';
    $environment['_DD_DEBUG_SIDECAR_IDLE_LINGER_TIME_SECS'] = '1';
    $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL'] =
        'http://' . stream_socket_get_name($server, false) . '/configuration';
    $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_POLL_INTERVAL_SECONDS'] = '1';
    $environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_REQUEST_TIMEOUT_SECONDS'] = '1';
    $environment['DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS'] = '1000';
    $environment['DD_API_KEY'] = 'local-fixture-must-not-receive-this';
    $environment['NO_PROXY'] = '127.0.0.1,localhost';
    $process = ffeTestProcess(__DIR__ . '/agentless_activation_app.php', $pipes, $environment);
    $stdout = $stderr = '';
    $requests = 0;
    $running = true;
    $exitCode = null;
    $timeout = getenv('USE_ZEND_ALLOC') === '0' ? 60 : 15;
    // Observe more than two configured polling intervals after child startup.
    $quietWindow = getenv('USE_ZEND_ALLOC') === '0' ? 5 : 2.2;
    $configuration = array(
        'createdAt' => '2026-05-22T00:00:00Z', 'format' => 'SERVER',
        'environment' => array('name' => 'test'),
        'flags' => array('flag' => array(
            'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
            'variations' => array('on' => array('key' => 'on', 'value' => 'blue')),
            'allocations' => array(array('key' => 'allocation', 'rules' => array(), 'doLog' => false,
                'splits' => array(array('variationKey' => 'on', 'shards' => array())))),
        )),
    );
    $body = json_encode(array('data' => array('type' => 'universal-flag-configuration', 'attributes' => $configuration)));
    $pump = function () use ($server, $pipes, $process, $body, &$stdout, &$stderr, &$requests, &$running, &$exitCode) {
        $stdout .= ffeTestProcessRead($pipes[1]);
        $stderr .= ffeTestProcessRead($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $running = false;
            if ($exitCode === null) $exitCode = $status['exitcode'];
        }
        if ($connection = @stream_socket_accept($server, 0.02)) {
            stream_set_timeout($connection, 2);
            try {
                $route = trim((string) fgets($connection));
                $headers = array();
                while (($line = fgets($connection)) !== false && trim($line) !== '') {
                    $pair = explode(':', $line, 2);
                    if (count($pair) === 2) $headers[strtolower($pair[0])] = trim($pair[1]);
                }
                if ($route !== 'GET /configuration HTTP/1.1' || isset($headers['dd-api-key'])) {
                    throw new RuntimeException('unexpected CDN request or managed credential forwarding');
                }
                ++$requests;
                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                    . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
            } finally {
                fclose($connection);
            }
        }
    };
    $waitFor = function ($condition, $seconds, $description) use ($pump, &$running, &$stdout, &$stderr) {
        $deadline = microtime(true) + $seconds;
        do {
            $pump();
            if ($condition()) return;
            if (!$running) throw new RuntimeException($description . ': child exited: ' . $stdout . ' ' . $stderr);
        } while (microtime(true) < $deadline);
        throw new RuntimeException($description . ': timed out: ' . $stdout . ' ' . $stderr);
    };
    $observe = function ($seconds) use ($pump, &$running, &$stderr) {
        $deadline = microtime(true) + $seconds;
        do {
            $pump();
            if (!$running) throw new RuntimeException('child exited during observation: ' . $stderr);
        } while (microtime(true) < $deadline);
    };
    try {
        $waitFor(function () use (&$stdout) { return strpos($stdout, "fixture_child_ready\n") !== false; }, $timeout, 'startup');
        if ($fetchBefore) {
            $waitFor(function () use (&$requests) { return $requests > 0; }, $timeout, 'explicit source activation');
        } else {
            $observe($quietWindow);
        }
        // Print all phase results so failures identify the exact opt-in boundary.
        echo $name, '.before_registration=', $requests ? 'fetching' : 'quiet', "\n";
        $beforeRegistration = $requests;
        fwrite($pipes[0], "register\n");
        $waitFor(function () use (&$stdout) { return strpos($stdout, "fixture_provider_registered\n") !== false; }, $timeout, 'provider registration');
        if ($fetchAfter) {
            $waitFor(function () use (&$requests, $beforeRegistration) { return $requests > $beforeRegistration; }, $timeout, 'fetching after registration');
        } else {
            $observe($quietWindow);
        }
        echo $name, '.after_registration=', $requests > $beforeRegistration ? 'fetching' : 'quiet', "\n";
        fwrite($pipes[0], "evaluate\n");
        $waitFor(function () use (&$stdout) { return preg_match('/fixture_value=[^\r\n]*\r?\n/', $stdout); }, $timeout, 'flag evaluation');
        if (!preg_match('/fixture_value=([^\r\n]*)\r?\n/', $stdout, $match)) throw new RuntimeException('incomplete evaluation result');
        echo $name, '.value=', $match[1], "\n";
        if (!$fetchAfter) $observe($quietWindow);
        fwrite($pipes[0], "finish\n");
        $waitFor(function () use (&$running) { return !$running; }, $timeout, 'shutdown');
        if ($exitCode !== 0) throw new RuntimeException('child failed: ' . $stdout . ' ' . $stderr);
        if (!$fetchAfter && $requests !== 0) throw new RuntimeException('inactive provider sent a CDN request');
    } finally {
        if ($running) proc_terminate($process);
        foreach ($pipes as $pipe) fclose($pipe);
        proc_close($process);
        fclose($server);
    }
}
