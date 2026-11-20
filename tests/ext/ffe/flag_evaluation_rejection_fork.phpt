--TEST--
FFE evaluation rejections and exposure outcomes belong to the process that recorded them
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (getenv('PHP_PEAR_RUNTESTS') === '1') die('skip: pecl run-tests does not support {PWD}');
// Completed records are safe to remove; the SKIPIF sidecar may still be
// publishing .json.tmp files. FILE filters records by its own process IDs.
foreach (glob(__DIR__ . '/tmp/ffe-rejection-fork/telemetry-*.json') as $file) {
    if (!@unlink($file) && file_exists($file)) die('cannot remove stale telemetry');
}
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_FEATURE_FLAGS_EXPOSURES_ADMISSION_TIMEOUT_US=0
DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED=true
DD_TRACE_CLI_ENABLED=1
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
DD_TRACE_LOG_LEVEL=off
--INI--
datadog.trace.agent_url="file://{PWD}/tmp/ffe-rejection-fork/"
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
    foreach (array('', 'allocation') as $allocation) {
        $status = \DDTrace\Internal\record_ffe_exposure('flag', 'subject', array(), $allocation, 'blue');
        if ($status !== 'unavailable') throw new RuntimeException('unexpected batch-only status: ' . $status);
    }
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
// The pcntl integration starts a new root span for the fork. Restore the
// parent's named telemetry application before draining its rejection counters.
setApplication($parentService);
dd_trace_internal_fn('finalize_telemetry');

// Separate applications expose inherited counters instead of letting a stopped
// telemetry worker silently discard the child's data. Wait for both to finish.
$services = array($parentService => 'parent', 'ffe-rejection-child-' . $pid => 'child');
$path = __DIR__ . '/tmp/ffe-rejection-fork/telemetry-*.json';
$deadline = microtime(true) + 30;
do {
    usleep(10000);
    $counts = array('parent' => 0, 'child' => 0);
    $outcomes = array('parent' => array('deferred' => 0, 'invalid_input' => 0), 'child' => array('deferred' => 0, 'invalid_input' => 0));
    $closed = array();
    // Directory capture publishes complete requests atomically and avoids
    // inheriting the single-file recorder's locked mutex across a fork.
    foreach (glob($path) as $file) {
        $envelope = json_decode(file_get_contents($file), true);
        $service = $envelope['application']['service_name'] ?? '';
        if (!isset($services[$service])) continue;
        $batch = $envelope['request_type'] === 'message-batch' ? $envelope['payload'] : array($envelope);
        foreach ($batch as $event) {
            if ($event['request_type'] === 'app-closing') $closed[$service] = true;
            if ($event['request_type'] !== 'generate-metrics') continue;
            foreach ($event['payload']['series'] as $series) {
                if ($series['metric'] === 'exposures.rows') {
                    $tags = $series['tags'];
                    $name = count($tags) === 1 ? substr($tags[0], strlen('outcome:')) : '';
                    if ($series['namespace'] !== 'tracers' || !isset($outcomes[$services[$service]][$name]) || $tags[0] !== 'outcome:' . $name) {
                        throw new RuntimeException('unexpected exposure outcome dimensions');
                    }
                    foreach ($series['points'] as $point) $outcomes[$services[$service]][$name] += $point[1];
                }
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
echo 'exposure_outcomes=', json_encode($outcomes), "\n";
?>
--EXPECT--
completed=bool(true)
drop_counts={"parent":2,"child":1}
exposure_outcomes={"parent":{"deferred":2,"invalid_input":2},"child":{"deferred":1,"invalid_input":1}}
--CLEAN--
<?php
foreach (glob(__DIR__ . '/tmp/ffe-rejection-fork/telemetry-*') as $file) {
    @unlink($file);
}
@rmdir(__DIR__ . '/tmp/ffe-rejection-fork');
