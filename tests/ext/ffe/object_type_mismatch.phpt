--TEST--
FFE native object evaluations reject scalar JSON before logging and exposure recording
--INI--
datadog.trace.log_level="debug,ddtrace=trace"
datadog.trace.log_file={PWD}/object_type_mismatch.log
datadog.trace.generate_root_span=0
--FILE--
<?php
require __DIR__ . '/logging.inc';

$values = array(
    'json-int' => 42,
    'json-string' => 'text',
    'json-null' => null,
    'json-object' => array('a' => 1),
    'json-list' => array(1, 2),
);
$flags = array();
foreach ($values as $key => $value) {
    $flags[$key] = array(
        'key' => $key, 'enabled' => true, 'variationType' => 'JSON',
        'variations' => array('on' => array('key' => 'on', 'value' => $value)),
        'allocations' => array(array(
            'key' => 'allocation', 'rules' => array(),
            'splits' => array(array('variationKey' => 'on', 'shards' => array())),
            'doLog' => true,
        )),
    );
}
var_dump(DDTrace\Testing\ffe_load_config(json_encode(array(
    'createdAt' => '2024-01-01T00:00:00Z',
    'environment' => array('name' => 'test'),
    'flags' => $flags,
))));

foreach (array_keys($values) as $key) {
    $result = DDTrace\ffe_evaluate($key, DDTrace\FFE_OBJECT, null, array());
    echo $key . ': ' . $result->reason . ' ' . $result->errorCode
        . ' ' . json_encode($result->variant) . ' ' . $result->valueJson
        . ' ' . json_encode($result->doLog) . "\n";
}

// Rejected scalar values must not be exposed even though the allocation sets doLog.
$logs = read_log_snapshot(__DIR__ . '/object_type_mismatch.log');
foreach (array('json-int', 'json-string', 'json-null') as $key) {
    $line = 'Feature Flags: native evaluation {"flagKey":"' . $key . '","valueType":"object","targetingKey":null,"attributes":{},"valueJson":"null","variant":null,"allocationKey":null,"reason":"ERROR","errorCode":"TYPE_MISMATCH","configVersion":1,"doLog":false}';
    echo (strpos($logs, $line) !== false ? 'found' : 'MISSING: ' . $line) . "\n";
}
foreach (array('json-object', 'json-list') as $key) {
    $line = 'Feature Flags: native evaluation {"flagKey":"' . $key . '","valueType":"object","targetingKey":null,"attributes":{},"valueJson":' . json_encode(json_encode($values[$key])) . ',"variant":"on","allocationKey":"allocation","reason":"STATIC","errorCode":null,"configVersion":1,"doLog":true}';
    echo (strpos($logs, $line) !== false ? 'found' : 'MISSING: ' . $line) . "\n";
}
?>
--EXPECT--
bool(true)
json-int: 5 1 null null false
json-string: 5 1 null null false
json-null: 5 1 null null false
json-object: 0 0 "on" {"a":1} true
json-list: 0 0 "on" [1,2] true
found
found
found
found
found
--CLEAN--
<?php @unlink(__DIR__ . '/object_type_mismatch.log'); ?>
