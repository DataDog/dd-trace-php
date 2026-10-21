--TEST--
FFE object flags with scalar JSON values are reported as the type mismatch the SDK returns
--INI--
datadog.trace.log_level="debug,ddtrace=trace"
datadog.trace.log_file={PWD}/object_type_mismatch.log
datadog.trace.generate_root_span=0
--FILE--
<?php
require __DIR__ . '/logging.inc';

$root = getenv('TEST_PHP_SRCDIR') ?: dirname(__DIR__, 3);
$srcRoot = rtrim((string) ini_get('datadog.trace.sources_path'), '/');
if ($srcRoot === '' || !is_dir($srcRoot . '/api')) {
    $srcRoot = $root . '/src';
}
// PECL installs sources separately from tests, and its CI job blanks sources_path.
if (!is_dir($srcRoot . '/api')) {
    $installed = stream_resolve_include_path('datadog_trace/src/api/Log/LoggerInterface.php');
    if ($installed !== false) {
        $srcRoot = dirname(dirname(dirname($installed)));
    }
}
spl_autoload_register(function ($class) use ($srcRoot) {
    if (strpos($class, 'DDTrace\\') === 0) {
        $file = $srcRoot . '/api/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

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

$client = new DDTrace\FeatureFlags\Client();
foreach (array_keys($values) as $key) {
    $details = $client->getObjectDetails($key, array('default' => true));
    echo $key . ': ' . $details->getReason() . ' ' . json_encode($details->getErrorCode())
        . ' ' . json_encode($details->getVariant()) . ' ' . json_encode($details->getValue()) . "\n";
}

// The native evaluation (logged, measured and exposed) must match the SDK result,
// so rejected scalar values are not exposed even though the allocation sets doLog.
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
json-int: ERROR "TYPE_MISMATCH" null {"default":true}
json-string: ERROR "TYPE_MISMATCH" null {"default":true}
json-null: ERROR "TYPE_MISMATCH" null {"default":true}
json-object: STATIC null "on" {"a":1}
json-list: STATIC null "on" [1,2]
found
found
found
found
found
--CLEAN--
<?php @unlink(__DIR__ . '/object_type_mismatch.log'); ?>
