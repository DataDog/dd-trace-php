--TEST--
FFE fork children report only their own rejected observations
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (getenv('PHP_PEAR_RUNTESTS') === '1') die('skip: pecl run-tests does not support {PWD}');
require __DIR__ . '/../includes/clear_skipif_telemetry.inc';
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLI_ENABLED=1
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
DD_TRACE_LOG_LEVEL=off
--INI--
datadog.trace.agent_url="file://{PWD}/ffe-rejection-fork.out"
--FILE--
<?php
function setApplication($service) {
    $span = \DDTrace\start_span();
    $span->service = $service;
    $span->env = 'test';
    \DDTrace\close_span();
    dd_trace_serialize_closed_spans();
}

function rejectEvaluation() {
    if (\DDTrace\Internal\record_ffe_flag_evaluation(
        '', 'blue', null, null, array(), null, false, false, 1700000000123
    )) throw new RuntimeException('invalid observation was accepted');
}

$parentService = 'ffe-rejection-parent-' . getmypid();
setApplication($parentService);
rejectEvaluation();
rejectEvaluation();
$pid = pcntl_fork();
if ($pid === -1) throw new RuntimeException('fork failed');
if ($pid === 0) {
    setApplication('ffe-rejection-child-' . getmypid());
    rejectEvaluation();
    dd_trace_internal_fn('finalize_telemetry');
    exit(0);
}
pcntl_waitpid($pid, $status);
if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
    throw new RuntimeException('child failed');
}
dd_trace_internal_fn('finalize_telemetry');

// Separate applications expose inherited counters instead of letting a stopped
// telemetry worker silently discard the child's data. Wait for both to finish.
$services = array($parentService => 'parent', 'ffe-rejection-child-' . $pid => 'child');
$path = __DIR__ . '/ffe-rejection-fork.out';
$deadline = microtime(true) + 30;
do {
    usleep(10000);
    $counts = array('parent' => 0, 'child' => 0);
    $closed = array();
    foreach (is_file($path) ? file($path) : array() as $line) {
        $envelope = json_decode($line, true);
        $service = $envelope['application']['service_name'] ?? '';
        if (!isset($services[$service])) continue;
        $batch = $envelope['request_type'] === 'message-batch' ? $envelope['payload'] : array($envelope);
        foreach ($batch as $event) {
            if ($event['request_type'] === 'app-closing') $closed[$service] = true;
            if ($event['request_type'] !== 'generate-metrics') continue;
            foreach ($event['payload']['series'] as $series) {
                if ($series['metric'] !== 'flagevaluation.rows.dropped') continue;
                if ($series['namespace'] !== 'tracers' || $series['tags'] !== array('reason:serialization_error')) {
                    throw new RuntimeException('unexpected drop metric dimensions');
                }
                foreach ($series['points'] as $point) $counts[$services[$service]] += $point[1];
            }
        }
    }
} while (count($closed) !== 2 && microtime(true) < $deadline);
echo 'completed='; var_dump(count($closed) === 2);
echo 'drop_counts=', json_encode($counts), "\n";
?>
--EXPECT--
completed=bool(true)
drop_counts={"parent":2,"child":1}
--CLEAN--
<?php
@unlink(__DIR__ . '/ffe-rejection-fork.out');
