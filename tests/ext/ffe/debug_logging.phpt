--TEST--
FFE debug logs describe configuration, raw evaluation and exposure outcomes
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
var_dump(DDTrace\Testing\flush_ffe_exposures());

$file = __DIR__ . '/debug_logging.log';
$logs = read_log_snapshot($file);
foreach (array(
    'Feature Flags: enabled; starting remote_config configuration source',
    'Feature Flags: configuration applied successfully (1 flag(s): 1 usable, 0 disabled, 0 rejected; delivered env="test", created_at=2024-01-01 00:00:00 UTC, version=1, no previous configuration)',
    'Feature Flags: configuration rejected: EOF while parsing',
    '; retaining previous configuration (version=1)',
    'Feature Flags: native evaluation {"flagKey":"flag","valueType":"string","targetingKey":"secret-user","attributes":{"email":"secret-email"},"valueJson":"\\"secret-value\\"","variant":"on","allocationKey":"allocation","reason":"STATIC","errorCode":null,"configVersion":1,"doLog":true}',
    'Feature Flags: native evaluation {"flagKey":"missing","valueType":"string","targetingKey":"secret-user","attributes":{},"valueJson":"null","variant":null,"allocationKey":null,"reason":"ERROR","errorCode":"FLAG_NOT_FOUND","configVersion":1,"doLog":false}',
    'Feature Flags: dropped 1 exposure(s); buffer limit reached (1000)',
    'Feature Flags: handed off 1000 exposure(s) to sidecar; the sidecar logs delivery as ffe_exposures_flusher',
    'Feature Flags: exposure flush complete; empty queue (0 exposures)',
) as $message) {
    echo $message . ': ' . (strpos($logs, $message) !== false ? 'yes' : 'NO') . "\n";
}

// Only scalar attributes reach the evaluator, so only they are logged, and
// logging never runs a throwing jsonSerialize().
class ThrowingAttribute implements JsonSerializable {
    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        throw new Exception('jsonSerialize must not run');
    }
}
DDTrace\ffe_evaluate('missing', DDTrace\FFE_STRING, null, array('plan' => 'pro', 7 => 1.5, 'object' => new ThrowingAttribute(), 'list' => array(1)));
echo (strpos(read_log_snapshot($file), '"targetingKey":null,"attributes":{"plan":"pro","7":1.5},"valueJson":"null"') !== false ? 'scalar attributes logged' : 'MISSING scalar attributes') . "\n";
DDTrace\ffe_evaluate('missing', DDTrace\FFE_STRING, null, array('number' => INF));
echo (strpos(read_log_snapshot($file), '"number":{"nonFiniteFloat":"INF"}') !== false ? 'non-finite attribute explicit' : 'MISSING non-finite attribute') . "\n";

$partial = json_decode($config, true);
$partial['flags']['invalid'] = array('key' => 'invalid');
$partial['flags']['bad-type'] = $partial['flags']['flag'];
$partial['flags']['bad-type']['key'] = 'bad-type';
$partial['flags']['bad-type']['variations']['on']['value'] = 42;
$partial['flags']['bad-split'] = $partial['flags']['flag'];
$partial['flags']['bad-split']['key'] = 'bad-split';
$partial['flags']['bad-split']['allocations'][0]['splits'][0]['variationKey'] = 'missing';
DDTrace\Testing\ffe_load_config(json_encode($partial));
$logs = read_log_snapshot($file);
var_dump(strpos($logs, '4 flag(s): 1 usable, 0 disabled, 3 rejected') !== false);
// The shared evaluator exposes invalid flag keys, but not individual parser causes.
var_dump(strpos($logs, '3 flag(s) rejected as invalid or unsupported; evaluating them returns the default value with PARSE_ERROR: ["bad-split", "bad-type", "invalid"]') !== false);
unset($partial['flags']['flag'], $partial['flags']['bad-type'], $partial['flags']['bad-split']);
DDTrace\Testing\ffe_load_config(json_encode($partial));
var_dump(strpos(read_log_snapshot($file), '1 flag(s): 0 usable, 0 disabled, 1 rejected') !== false);
$partial['flags'] = (object) array();
DDTrace\Testing\ffe_load_config(json_encode($partial));
var_dump(DDTrace\ffe_has_config());
var_dump(strpos(read_log_snapshot($file), '0 flag(s): 0 usable, 0 disabled, 0 rejected') !== false);

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
Feature Flags: configuration applied successfully (1 flag(s): 1 usable, 0 disabled, 0 rejected; delivered env="test", created_at=2024-01-01 00:00:00 UTC, version=1, no previous configuration): yes
Feature Flags: configuration rejected: EOF while parsing: yes
; retaining previous configuration (version=1): yes
Feature Flags: native evaluation {"flagKey":"flag","valueType":"string","targetingKey":"secret-user","attributes":{"email":"secret-email"},"valueJson":"\"secret-value\"","variant":"on","allocationKey":"allocation","reason":"STATIC","errorCode":null,"configVersion":1,"doLog":true}: yes
Feature Flags: native evaluation {"flagKey":"missing","valueType":"string","targetingKey":"secret-user","attributes":{},"valueJson":"null","variant":null,"allocationKey":null,"reason":"ERROR","errorCode":"FLAG_NOT_FOUND","configVersion":1,"doLog":false}: yes
Feature Flags: dropped 1 exposure(s); buffer limit reached (1000): yes
Feature Flags: handed off 1000 exposure(s) to sidecar; the sidecar logs delivery as ffe_exposures_flusher: yes
Feature Flags: exposure flush complete; empty queue (0 exposures): yes
scalar attributes logged
non-finite attribute explicit
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/debug_logging.log'); ?>
