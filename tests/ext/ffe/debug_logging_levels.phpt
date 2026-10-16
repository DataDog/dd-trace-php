--TEST--
FFE per-evaluation details require trace logging; DD_TRACE_DEBUG keeps only sparse diagnostics
--INI--
datadog.trace.debug=1
datadog.trace.log_level="debug,ddtrace=trace"
datadog.trace.log_file={PWD}/debug_logging_levels.log
datadog.trace.generate_root_span=0
datadog.experimental_flagging_provider_enabled=1
--FILE--
<?php
require __DIR__ . '/logging.inc';

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
for ($i = 0; $i < 1001; ++$i) {
    DDTrace\ffe_evaluate('flag', DDTrace\FFE_BOOL, null, array());
}
DDTrace\ffe_evaluate('missing', DDTrace\FFE_BOOL, null, array());
DDTrace\Testing\flush_ffe_exposures();

// DD_TRACE_DEBUG wins over the configured trace level: configuration changes
// and lost exposures are reported, per-evaluation details are not.
$file = __DIR__ . '/debug_logging_levels.log';
$logs = read_log_snapshot($file);
var_dump(strpos($logs, 'Feature Flags: configuration applied successfully') !== false);
var_dump(strpos($logs, 'Feature Flags: dropped 1 exposure(s); buffer limit reached (1000)') !== false);
var_dump(strpos($logs, 'Feature Flags: evaluated ') === false);
var_dump(strpos($logs, 'Feature Flags: handed off ') === false);

// Removing the debug override allows the explicitly configured trace level.
ini_set('datadog.trace.debug', '0');
DDTrace\ffe_evaluate('flag', DDTrace\FFE_BOOL, null, array());
DDTrace\ffe_evaluate('missing', DDTrace\FFE_BOOL, null, array());
DDTrace\Testing\flush_ffe_exposures();
$logs = read_log_snapshot($file);
var_dump(substr_count($logs, 'Feature Flags: evaluated ') === 2);
var_dump(strpos($logs, 'Feature Flags: handed off 1 exposure(s) to sidecar') !== false);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/debug_logging_levels.log'); ?>
