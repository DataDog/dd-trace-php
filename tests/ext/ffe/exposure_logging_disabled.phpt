--TEST--
Disabling Datadog exposure logging leaves the PHP exposure buffer empty
--ENV--
DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED=false
--INI--
datadog.trace.log_file={PWD}/exposure_logging_disabled.log
datadog.trace.log_level="debug,ddtrace=trace"
datadog.trace.generate_root_span=0
--FILE--
<?php
require __DIR__ . '/logging.inc';

DDTrace\Testing\ffe_load_config(json_encode(array(
    'createdAt' => '2024-01-01T00:00:00Z',
    'environment' => array('name' => 'test'),
    'flags' => array('flag' => array(
        'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
        'variations' => array('on' => array('key' => 'on', 'value' => 'on')),
        'allocations' => array(array(
            'key' => 'allocation', 'rules' => array(), 'doLog' => true,
            'splits' => array(array('variationKey' => 'on', 'shards' => array())),
        )),
    )),
)));
DDTrace\ffe_evaluate('flag', DDTrace\FFE_STRING, 'user', array());
DDTrace\ffe_evaluate('flag', DDTrace\FFE_STRING, 'user', array());
DDTrace\Internal\record_ffe_exposure('flag', 'other', array(), 'allocation', 'on');
var_dump(DDTrace\Testing\flush_ffe_exposures());
$logs = read_log_snapshot(__DIR__ . '/exposure_logging_disabled.log');
var_dump(substr_count($logs, 'exposure not queued; DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED=false'));
var_dump(strpos($logs, 'Feature Flags: exposure queued') === false);

ini_set('datadog.feature_flags_exposures_datadog_logging_enabled', '1');
DDTrace\Internal\record_ffe_exposure('flag', 'third', array(), 'allocation', 'on');
var_dump(strpos(read_log_snapshot(__DIR__ . '/exposure_logging_disabled.log'), 'Feature Flags: exposure queued') !== false);
?>
--EXPECT--
bool(false)
int(3)
bool(true)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/exposure_logging_disabled.log'); ?>
