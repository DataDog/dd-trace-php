--TEST--
Interleaved repeats retain bounded PHP memory and preserve assignment changes in a single exposure batch
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and stream sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLI_ENABLED=1
DD_TRACE_AGENT_TIMEOUT=5000
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
_DD_DEBUG_SIDECAR_IPC_MODE=instance_per_process
DD_SERVICE=php-ffe-pending-repeats
--FILE--
<?php
require __DIR__ . '/stubs/exposure_capture.inc';
$rows = ffeCaptureExposures(__DIR__ . '/stubs/exposure_pending_app.php', array(8 => 'handoff='), 8);
foreach ($rows as $row) {
    echo json_encode(array($row['flag']['key'], $row['subject']['id'], $row['allocation']['key'], $row['variant']['key'],
        $row['serial_id'] ?? null, $row['subject']['attributes']['index'], $row['features']['index'])), "\n";
}
?>
--EXPECT--
bounded=true
handoff=true
["flag","user","A","on",null,0,"0"]
["other","user","A","on",null,0,"0"]
["flag","other","A","on",null,0,"0"]
["flag","user","B","on",null,1,"1"]
["flag","user","A","on",null,2,"2"]
["flag","user","A","off",null,3,"3"]
["flag","user","A","off",0,4,"4"]
["flag","user","A","off",2,5,"5"]
