--TEST--
FFE hook-only admission updates the shared assignment
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_SERVICE=php-ffe-hook-only
DD_FEATURE_FLAGS_EXPOSURES_DATADOG_LOGGING_ENABLED=false
--INI--
datadog.trace.log_file={PWD}/exposure_logging_disabled.log
datadog.trace.log_level="debug,ddtrace=trace"
datadog.trace.generate_root_span=0
--FILE--
<?php
require_once __DIR__ . '/stubs/exposure_sidecar.inc';
$scope = uniqid('admission-', true);
ini_set('datadog.trace.agent_test_session_token', $scope);
ffeAwaitExposureSidecar();
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
var_dump(DDTrace\ffe_evaluate('flag', DDTrace\FFE_STRING, 'user', array())->exposureCacheHit);
var_dump(DDTrace\ffe_evaluate('flag', DDTrace\FFE_STRING, 'user', array())->exposureCacheHit);
var_dump(DDTrace\Internal\record_ffe_exposure('flag', 'other', array(), 'allocation', 'on'));
var_dump(DDTrace\Testing\flush_ffe_exposures());
$logs = read_log_snapshot(__DIR__ . '/exposure_logging_disabled.log');
var_dump(substr_count($logs, 'Feature Flags: exposure admission admitted'));
var_dump(substr_count($logs, 'Feature Flags: exposure admission repeat'));

ini_set('datadog.feature_flags_exposures_datadog_logging_enabled', '1');
var_dump(DDTrace\Internal\record_ffe_exposure('flag', 'third', array(), 'allocation', 'on'));
var_dump(DDTrace\Testing\flush_ffe_exposures());
?>
--EXPECT--
bool(false)
bool(true)
string(8) "admitted"
bool(false)
int(2)
int(1)
string(8) "admitted"
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/exposure_logging_disabled.log'); ?>
