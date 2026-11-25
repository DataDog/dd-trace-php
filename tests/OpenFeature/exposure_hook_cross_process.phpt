--TEST--
Customer hooks receive repeated exposures while the sidecar sends assignment changes across workers
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000 || !is_file(__DIR__ . '/vendor/autoload.php')) die('skip: OpenFeature dependencies required');
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and stream sockets required');
?>
--ENV--
DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED=true
DD_REMOTE_CONFIG_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLI_ENABLED=1
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
--FILE--
<?php
require __DIR__ . '/../ext/ffe/stubs/exposure_capture.inc';
$rows = ffeCaptureExposures(__DIR__ . '/../Common/FeatureFlags/exposure_hook_workers.php', [1 => 'first:', 2 => 'other_worker:'], 3);
echo 'exposures=', json_encode(array_column(array_column($rows, 'allocation'), 'key')), "\n";
?>
--EXPECT--
["parent",true,"flag","user","allocation-a","on",1,{"holdout.key":"h1"}]
first: handed_off=true
["child",true,"flag","user","allocation-b","on",1,{"holdout.key":"h1"}]
other_worker: handed_off=true
["parent",true,"flag","user","allocation-a","on",1,{"holdout.key":"h1"}]
back: handed_off=true
["parent",true,"flag","user","allocation-a","on",1,{"holdout.key":"h1"}]
repeat: handed_off=true
exposures=["allocation-a","allocation-b","allocation-a"]
