<?php
require_once __DIR__ . '/ffe_process.inc';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$server) throw new RuntimeException($error);
$environment = getenv();
$environment['DD_FEATURE_FLAGS_CONFIGURATION_SOURCE_AGENTLESS_BASE_URL'] = 'http://' . stream_socket_get_name($server, false);
$environment['DD_EXPERIMENTAL_FLAGGING_PROVIDER_INITIALIZATION_TIMEOUT_MS'] = '10000';
$keys = array('DD_FEATURE_FLAGS_ENABLED', 'DD_FEATURE_FLAGS_CONFIGURATION_SOURCE', 'DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED');
foreach ($cases as $name => $case) {
    $environment['DD_TRACE_SIDECAR_CONNECTION_MODE'] = $name === 'thread_mode' ? 'thread' : 'auto';
    foreach ($keys as $i => $key) {
        unset($environment[$key]);
        if ($case[$i] !== null) $environment[$key] = $case[$i];
    }
    $process = ffeTestProcess(__DIR__ . '/agentless_source_selection_app.php', $pipes, $environment);
    if (!is_resource($process)) throw new RuntimeException('cannot launch PHP');
    fclose($pipes[0]);
    $stdout = $stderr = '';
    $start = microtime(true);
    $readyAt = null;
    do {
        $stdout .= ffeTestProcessRead($pipes[1]);
        $stderr .= ffeTestProcessRead($pipes[2]);
        if ($readyAt === null && strpos($stdout, "fixture_child_ready\n") !== false) $readyAt = microtime(true);
        $status = proc_get_status($process);
        if (!$status['running']) break;
        if ($connection = @stream_socket_accept($server, 0.05)) {
            fclose($connection);
            proc_terminate($process);
            throw new RuntimeException($name . ' unexpectedly polled agentless configuration');
        }
    } while (microtime(true) - ($readyAt === null ? $start : $readyAt) < ($readyAt === null ? 60 : 3));
    $stdout .= ffeTestProcessRead($pipes[1]);
    $stderr .= ffeTestProcessRead($pipes[2]);
    if ($status['running']) proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($status['running'] || $status['exitcode'] !== 0) throw new RuntimeException($name . ' failed: ' . $stderr);
    $state = json_decode(str_replace("fixture_child_ready\n", '', $stdout), true);
    if ($state['enabled'] !== $case[3] || $state['mode'] !== $case[4] || $state['ready']) {
        throw new RuntimeException($name . ' wrong source: ' . $stdout);
    }
    if ($name === 'thread_mode' && $state['reason'] !== 'unsupported_sidecar_mode') {
        throw new RuntimeException('thread mode did not report its permanent failure');
    }
    echo $name, "=ok\n";
}
fclose($server);
