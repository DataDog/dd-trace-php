--TEST--
FFE debug logs describe configuration and exposure outcomes without customer data
--INI--
datadog.trace.log_file={PWD}/debug_logging.log
datadog.trace.log_level="debug,ddtrace=trace"
datadog.trace.generate_root_span=0
datadog.experimental_flagging_provider_enabled=1
--FILE--
<?php
require __DIR__ . '/logging.inc';

// This request-local value must not change the startup subscription diagnostic.
ini_set('datadog.experimental_flagging_provider_enabled', '0');
$config = json_encode(array(
    'createdAt' => '2024-01-01T00:00:00Z',
    'environment' => array('name' => 'test'),
    'flags' => array('flag' => array(
        'key' => 'flag', 'enabled' => true, 'variationType' => 'STRING',
        'variations' => array('on' => array('key' => 'on', 'value' => 'secret-value')),
        'allocations' => array(array(
            'key' => 'allocation', 'rules' => array(),
            'splits' => array(array('variationKey' => 'on', 'shards' => array())),
            'doLog' => true,
        )),
    )),
));

var_dump(DDTrace\Testing\ffe_load_config($config));
$version = DDTrace\ffe_config_version();
var_dump(DDTrace\Testing\ffe_load_config('{"secret-invalid-config":'));
var_dump(DDTrace\ffe_config_version() === $version);
for ($i = 0; $i < 1001; ++$i) {
    DDTrace\ffe_evaluate('flag', DDTrace\FFE_STRING, 'secret-user', array('email' => 'secret-email'));
}
DDTrace\ffe_evaluate('missing', DDTrace\FFE_STRING, 'secret-user', array());
var_dump(DDTrace\Testing\flush_ffe_exposures());
var_dump(DDTrace\Testing\flush_ffe_exposures()); // empty buffer is silent

$file = __DIR__ . '/debug_logging.log';
$logs = read_log_snapshot($file);
foreach (array(
    'Feature Flags: enabled; starting remote_config configuration source',
    'Feature Flags: configuration applied successfully (1 flag(s), env="test", version=',
    'Feature Flags: configuration rejected; invalid flag configuration, retaining previous configuration',
    'Feature Flags: evaluated {"flagKey":"flag","valueType":"string","reason":"STATIC","variant":"on","errorCode":null,"configVersion":1,"doLog":true}',
    'Feature Flags: evaluated {"flagKey":"missing","valueType":"string","reason":"ERROR","variant":null,"errorCode":"FLAG_NOT_FOUND","configVersion":1,"doLog":false}',
    'Feature Flags: dropped 1 exposure(s); buffer limit reached (1000)',
    'Feature Flags: handed off 1000 exposure(s) to sidecar',
) as $message) {
    echo $message . ': ' . (strpos($logs, $message) !== false ? 'yes' : 'NO') . "\n";
}
var_dump(strpos($logs, 'secret-') === false);

// Changing the existing log level must suppress all native debug diagnostics.
ini_set('datadog.trace.log_level', 'error');
$before = substr_count(read_log_snapshot($file), 'Feature Flags:');
DDTrace\Testing\ffe_load_config($config);
DDTrace\Testing\ffe_load_config('invalid');
DDTrace\ffe_evaluate('flag', DDTrace\FFE_STRING, null, array());
DDTrace\Testing\flush_ffe_exposures();
var_dump(substr_count(read_log_snapshot($file), 'Feature Flags:') === $before);
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(true)
bool(false)
Feature Flags: enabled; starting remote_config configuration source: yes
Feature Flags: configuration applied successfully (1 flag(s), env="test", version=: yes
Feature Flags: configuration rejected; invalid flag configuration, retaining previous configuration: yes
Feature Flags: evaluated {"flagKey":"flag","valueType":"string","reason":"STATIC","variant":"on","errorCode":null,"configVersion":1,"doLog":true}: yes
Feature Flags: evaluated {"flagKey":"missing","valueType":"string","reason":"ERROR","variant":null,"errorCode":"FLAG_NOT_FOUND","configVersion":1,"doLog":false}: yes
Feature Flags: dropped 1 exposure(s); buffer limit reached (1000): yes
Feature Flags: handed off 1000 exposure(s) to sidecar: yes
bool(true)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/debug_logging.log'); ?>
