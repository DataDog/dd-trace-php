--TEST--
Exposure telemetry distinguishes a single oversized row from deferred byte and row capacity
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die('skip: pecl run-tests does not support {PWD}');
if (PHP_OS === 'WINNT' && PHP_VERSION_ID < 70400) die('skip: synchronous telemetry file permissions');
require __DIR__ . '/../includes/clear_skipif_telemetry.inc';
?>
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_FEATURE_FLAGS_EXPOSURES_ADMISSION_TIMEOUT_US=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_LOG_LEVEL=off
--INI--
datadog.trace.agent_url="file://{PWD}/ffe-exposure-capacity.out"
--FILE--
<?php
require __DIR__ . '/../includes/telemetry_file.inc';
$services = array('ffe-byte-capacity-' . getmypid() => 'bytes', 'ffe-row-capacity-' . getmypid() => 'rows');
function startApplication($service) {
    $span = DDTrace\start_span();
    $span->service = $service;
    DDTrace\close_span();
    dd_trace_serialize_closed_spans();
}
function deferObservation($attributes) {
    static $id = 0;
    $status = DDTrace\Internal\record_ffe_exposure('flag', (string) ++$id, $attributes, 'alloc', 'blue');
    if ($status !== 'unavailable') throw new RuntimeException($status);
}
startApplication(key($services));
deferObservation(array('blob' => str_repeat('x', 8 * 1024 * 1024)));
$attributes = array('blob' => str_repeat('x', 3 * 1024 * 1024));
for ($i = 0; $i < 3; ++$i) deferObservation($attributes);
dd_trace_internal_fn('finalize_telemetry');
end($services);
startApplication(key($services));
for ($i = 0; $i < 999; ++$i) deferObservation(array());
dd_trace_internal_fn('finalize_telemetry');
$deadline = microtime(true) + 30;
do {
    $counts = array();
    $closed = array();
    foreach (read_telemetry_file(__DIR__ . '/ffe-exposure-capacity.out') as $event) {
        $service = $event['application']['service_name'] ?? '';
        if (!isset($services[$service])) continue;
        if ($event['request_type'] === 'app-closing') $closed[$service] = true;
        if ($event['request_type'] !== 'generate-metrics') continue;
        foreach ($event['payload']['series'] as $series) {
            if ($series['metric'] !== 'exposures.rows') continue;
            $tag = $services[$service] . ':' . $series['namespace'] . ':' . implode(',', $series['tags']);
            foreach ($series['points'] as $point) $counts[$tag] = ($counts[$tag] ?? 0) + $point[1];
        }
    }
    if (count($closed) !== 2) usleep(10000);
} while (count($closed) !== 2 && microtime(true) < $deadline);
if (count($closed) !== 2) throw new RuntimeException('telemetry application did not close');
ksort($counts);
echo json_encode($counts), "\n";
?>
--EXPECT--
{"bytes:tracers:outcome:deferred":2,"bytes:tracers:outcome:deferred_overflow":1,"bytes:tracers:outcome:oversize":1,"rows:tracers:outcome:deferred":998,"rows:tracers:outcome:deferred_overflow":1}
--CLEAN--
<?php
@unlink(__DIR__ . '/ffe-exposure-capacity.out');
