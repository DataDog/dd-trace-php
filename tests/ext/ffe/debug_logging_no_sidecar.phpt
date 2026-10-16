--TEST--
FFE debug logs report dropped exposures when the sidecar is unavailable
--SKIPIF--
<?php if (strncasecmp(PHP_OS, 'WIN', 3) === 0) die('skip: The sidecar trace sender cannot be disabled on Windows'); ?>
--INI--
datadog.trace.enabled=0
datadog.trace.sidecar_trace_sender=0
datadog.instrumentation_telemetry_enabled=0
datadog.trace.log_file={PWD}/debug_logging_no_sidecar.log
datadog.trace.log_level=debug
--FILE--
<?php
DDTrace\Testing\ffe_load_config(json_encode(array(
    'createdAt' => '2024-01-01T00:00:00Z',
    'environment' => array('name' => 'test'),
    'flags' => array('flag' => array(
        'key' => 'flag', 'enabled' => true, 'variationType' => 'BOOLEAN',
        'variations' => array('on' => array('key' => 'on', 'value' => true)),
        'allocations' => array(array(
            'key' => 'allocation', 'rules' => array(),
            'splits' => array(array('variationKey' => 'on', 'shards' => array())),
            'doLog' => true,
        )),
    )),
)));
DDTrace\ffe_evaluate('flag', DDTrace\FFE_BOOL, null, array());
var_dump(DDTrace\Testing\flush_ffe_exposures());
var_dump(DDTrace\Testing\flush_ffe_exposures());
$log = file_get_contents(__DIR__ . '/debug_logging_no_sidecar.log');
var_dump(substr_count($log, 'Feature Flags: dropping 1 exposure(s); sidecar or request is not initialized'));
var_dump(strpos($log, 'handed off to sidecar') === false);
?>
--EXPECT--
bool(false)
bool(false)
int(1)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/debug_logging_no_sidecar.log'); ?>
