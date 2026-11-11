--TEST--
FFE producer rejections are counted once without counting accepted or disabled observations
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die('skip: pecl run-tests does not support {PWD}');
if (PHP_OS === 'WINNT' && PHP_VERSION_ID < 70400) die('skip: synchronous telemetry file permissions');
require __DIR__ . '/../includes/clear_skipif_telemetry.inc';
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLI_ENABLED=1
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_LOG_LEVEL=off
--INI--
datadog.trace.agent_url="file://{PWD}/ffe-rejection-metrics.out"
--FILE--
<?php
function recordEvaluation($flag, $target = 'rejection-pii-canary@example.test') {
    return \DDTrace\Internal\record_ffe_flag_evaluation(
        $flag, 'blue', null, $target, array(), null, false, false, 1700000000123
    );
}

// Establish application attribution for ordinary telemetry, as in the
// existing telemetry tests with automatic root spans disabled.
$service = 'ffe-rejection-metrics-' . getmypid();
$span = \DDTrace\start_span();
$span->service = $service;
$span->env = 'test';
\DDTrace\close_span();
dd_trace_serialize_closed_spans();

// Invalid required metadata cannot be encoded. Optional identity loss is not
// a dropped evaluation; native telemetry accounts for its omission instead.
var_dump(recordEvaluation("invalid-\xFF"));
var_dump(recordEvaluation("invalid-\xFF"));
var_dump(recordEvaluation(''));
var_dump(recordEvaluation('valid', "invalid-\xFF"));
var_dump(recordEvaluation(str_repeat('x', 10 * 1024 * 1024)));
var_dump(recordEvaluation('degraded', str_repeat('x', 10 * 1024 * 1024)));
if (ini_set('datadog.flagging_evaluation_counts_enabled', '0') === false) {
    throw new RuntimeException('could not disable flagevaluation submission');
}
var_dump(recordEvaluation("disabled-\xFF"));
ini_set('datadog.flagging_evaluation_counts_enabled', '1');

// Evaluation must not reconnect. Wait for the existing broken-connection
// helper to take effect, then use ordinary lifecycle traffic to recover.
dd_trace_internal_fn('break_sidecar_connection');
$deadline = microtime(true) + 5;
do {
    usleep(10000);
    $accepted = recordEvaluation('after-disconnect');
} while ($accepted && microtime(true) < $deadline);
var_dump($accepted);
var_dump(recordEvaluation('still-disconnected'));
var_dump(recordEvaluation('still-disconnected'));
dd_trace_internal_fn('stats_sidecar');
var_dump(recordEvaluation('recovered'));
// Finish accepted EVP writes before CLEAN removes the shared capture file.
dd_trace_synchronous_flush();

// Finalize again for a fresh telemetry application. Reusing the already-stopped
// worker could hide duplicate points by discarding them, rather than proving
// that the PHP counters were drained.
dd_trace_internal_fn('finalize_telemetry');
$secondService = $service . '-second';
$span = \DDTrace\start_span();
$span->service = $secondService;
$span->env = 'test';
\DDTrace\close_span();
dd_trace_serialize_closed_spans();
dd_trace_internal_fn('finalize_telemetry');
$path = __DIR__ . '/ffe-rejection-metrics.out';
$deadline = microtime(true) + 30;
do {
    usleep(10000);
    $counts = array();
    $closed = array();
    $raw = is_file($path) ? file_get_contents($path) : '';
    foreach (explode("\n", $raw) as $line) {
        $envelope = json_decode($line, true);
        $application = $envelope['application']['service_name'] ?? '';
        if ($application !== $service && $application !== $secondService) continue;
        $batch = $envelope['request_type'] === 'message-batch' ? $envelope['payload'] : array($envelope);
        foreach ($batch as $event) {
            if ($event['request_type'] === 'app-closing') $closed[$application] = true;
            if ($event['request_type'] !== 'generate-metrics') continue;
            foreach ($event['payload']['series'] as $series) {
                if ($series['metric'] !== 'flagevaluation.rows.dropped') continue;
                $key = $series['namespace'] . ':' . implode(',', $series['tags']);
                foreach ($series['points'] as $point) {
                    $counts[$key] = ($counts[$key] ?? 0) + $point[1];
                }
            }
        }
    }
} while (count($closed) !== 2 && microtime(true) < $deadline);
ksort($counts);
echo 'completed='; var_dump(count($closed) === 2);
echo 'drop_counts=', json_encode($counts), "\n";
echo 'diagnostics_pii_absent=';
var_dump(strpos($raw, 'rejection-pii-canary@example.test') === false);
?>
--EXPECT--
bool(false)
bool(false)
bool(false)
bool(true)
bool(false)
bool(true)
bool(false)
bool(false)
bool(false)
bool(false)
bool(true)
completed=bool(true)
drop_counts={"tracers:reason:payload_limit":1,"tracers:reason:serialization_error":3,"tracers:reason:unavailable":3}
diagnostics_pii_absent=bool(true)
--CLEAN--
<?php
@unlink(__DIR__ . '/ffe-rejection-metrics.out');
