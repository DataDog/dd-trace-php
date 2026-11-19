--TEST--
FFE per-evaluation details and exposure handoffs are visible under DD_TRACE_DEBUG
--ENV--
DD_SERVICE=php-ffe-debug-admission-levels
--INI--
datadog.trace.debug=1
datadog.trace.log_level="debug,ddtrace=trace"
datadog.trace.log_file={PWD}/debug_logging_levels.log
datadog.trace.generate_root_span=0
datadog.experimental_flagging_provider_enabled=1
--FILE--
<?php
require_once __DIR__ . '/stubs/exposure_sidecar.inc';
ffeAwaitExposureSidecar();
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
for ($i = 0; $i < 2; ++$i) {
    DDTrace\ffe_evaluate('flag', DDTrace\FFE_BOOL, null, array());
}
DDTrace\ffe_evaluate('missing', DDTrace\FFE_BOOL, null, array());
DDTrace\Testing\flush_ffe_exposures();

// DD_TRACE_DEBUG forces the debug level, which carries every evaluation,
// exposure handoff and lost exposure.
$file = __DIR__ . '/debug_logging_levels.log';
$logs = read_log_snapshot($file);
var_dump(strpos($logs, 'Feature Flags: configuration applied successfully') !== false);
var_dump(strpos($logs, 'Feature Flags: exposure admission repeat') !== false);
var_dump(substr_count($logs, 'Feature Flags: native evaluation ') === 3);
var_dump(strpos($logs, 'Feature Flags: exposure admission admitted') !== false);

// Without the debug override, the configured level keeps the same details.
ini_set('datadog.trace.debug', '0');
DDTrace\ffe_evaluate('flag', DDTrace\FFE_BOOL, null, array());
DDTrace\ffe_evaluate('missing', DDTrace\FFE_BOOL, null, array());
DDTrace\Testing\flush_ffe_exposures();
$logs = read_log_snapshot($file);
var_dump(substr_count($logs, 'Feature Flags: native evaluation ') === 5);
var_dump(substr_count($logs, 'Feature Flags: exposure admission repeat') === 2);
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
