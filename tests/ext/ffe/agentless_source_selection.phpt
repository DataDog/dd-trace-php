--TEST--
FFE stable and legacy source settings control native activation without configuration traffic
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_TRACE_ENABLED=0
DD_TRACE_CLI_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_AGENT_URL=http://127.0.0.1:1
--FILE--
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) throw new RuntimeException($error);
$environment = getenv();
$environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL'] = 'http://' . stream_socket_get_name($server, false);
$environment['DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS'] = '10000';
$cases = array(
    'stable_kill' => array('0', 'agentless', '1', false, 'native_agentless'),
    'legacy_kill' => array(null, null, '0', false, 'native_remote_config'),
    'legacy_remote_config' => array(null, null, '1', true, 'native_remote_config'),
    'explicit_remote_config' => array('1', 'remote_config', '0', true, 'native_remote_config'),
    'offline' => array('1', 'offline', '1', false, 'native_disabled'),
    'invalid' => array('1', 'unknown', '1', false, 'native_disabled'),
);
$keys = array('DD_FEATURE_FLAGS_ENABLED', 'DD_FEATURE_FLAGS_CONFIGURATION_SOURCE', 'DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED');
$script = '\DDTrace\ffe_evaluate("flag", \DDTrace\FFE_STRING, null, array(), false); echo json_encode(\DDTrace\Internal\ffe_provider_state());';
foreach ($cases as $name => $case) {
    foreach ($keys as $i => $key) {
        unset($environment[$key]);
        if ($case[$i] !== null) $environment[$key] = $case[$i];
    }
    $command = 'exec ' . escapeshellarg(getenv('TEST_PHP_EXECUTABLE')) . ' ' . getenv('TEST_PHP_EXTRA_ARGS') . ' -r ' . escapeshellarg($script);
    $process = proc_open($command, array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')), $pipes, null, $environment);
    if (!is_resource($process)) throw new RuntimeException('cannot launch PHP');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = $stderr = '';
    $start = microtime(true);
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) break;
        if ($connection = @stream_socket_accept($server, 0.05)) {
            fclose($connection);
            proc_terminate($process);
            throw new RuntimeException($name . ' unexpectedly polled agentless configuration');
        }
    } while (microtime(true) - $start < 3);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    if ($status['running']) proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($status['running'] || $status['exitcode'] !== 0) throw new RuntimeException($name . ' failed: ' . $stderr);
    $state = json_decode($stdout, true);
    if ($state['enabled'] !== $case[3] || $state['mode'] !== $case[4] || $state['ready']) {
        throw new RuntimeException($name . ' wrong source: ' . $stdout);
    }
    echo $name, "=ok\n";
}
fclose($server);
?>
--EXPECT--
stable_kill=ok
legacy_kill=ok
legacy_remote_config=ok
explicit_remote_config=ok
offline=ok
invalid=ok
