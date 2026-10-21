--TEST--
FFE SDK evaluations log raw native results and returned values under DD_TRACE_DEBUG
--INI--
datadog.trace.debug=1
datadog.trace.log_level="debug,ddtrace=trace"
datadog.trace.log_file={PWD}/sdk_debug_logging.log
error_log={PWD}/sdk_debug_logging.log
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

$file = __DIR__ . '/sdk_debug_logging.log';
$hint = 'Feature Flags: flagging provider is disabled, so no flag configuration will be received and default values are returned; set DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true at startup (environment or php.ini) and restart';

// A request-local change must not be reported as enabling the startup subscription.
ini_set('datadog.experimental_flagging_provider_enabled', '1');

// DD_TRACE_DEBUG logs each evaluation twice: the native result with its raw
// inputs, and the value the SDK returns, next to the disabled-provider hint
// and the SDK's not-ready warning.
$client = new DDTrace\FeatureFlags\Client();
for ($i = 0; $i < 100; ++$i) {
    $client->getBooleanValue('missing', false, array('targetingKey' => 'secret-user'));
}
$logs = read_log_snapshot($file);
var_dump(substr_count($logs, $hint) === 1);
var_dump(strpos($logs, '[ddtrace] [warning] - Datadog-backed PHP feature flag evaluation has no Remote Configuration data loaded') !== false);
var_dump(substr_count($logs, 'Feature Flags: native evaluation {"flagKey":"missing","valueType":"boolean","targetingKey":"secret-user","attributes":{},"valueJson":"null","variant":null,"allocationKey":null,"reason":"ERROR","errorCode":"PROVIDER_NOT_READY","configVersion":0,"doLog":false}') === 100);
var_dump(substr_count($logs, '[ddtrace] [debug] - Feature Flags: evaluated {"flagKey":"missing","valueType":"boolean","targetingKey":"secret-user","attributes":{},"defaultValue":false,"value":false,"variant":null,"reason":"ERROR","errorCode":"PROVIDER_NOT_READY","errorMessage":"Datadog-backed PHP feature flag evaluation has no Remote Configuration data loaded for this request. Returning default values.","flagMetadata":{},"configVersion":0}') === 100);

// The configured debug level keeps native details without the override.
ini_set('datadog.trace.debug', '0');
var_dump($client->getBooleanValue('missing', true, array('targetingKey' => 'secret-user')));
DDTrace\Testing\ffe_load_config('{"createdAt":"2024-01-01T00:00:00Z","environment":{"name":"test"},"flags":{}}');
var_dump($client->getBooleanValue('missing', false));
$logs = read_log_snapshot($file);
foreach (array(
    'Feature Flags: configuration applied successfully (0 flag(s): 0 usable, 0 disabled, 0 rejected; delivered env="test"',
    'Feature Flags: native evaluation {"flagKey":"missing","valueType":"boolean","targetingKey":null,"attributes":{},"valueJson":"null","variant":null,"allocationKey":null,"reason":"ERROR","errorCode":"FLAG_NOT_FOUND","configVersion":1,"doLog":false}',
    'Feature Flags: evaluated {"flagKey":"missing","valueType":"boolean","targetingKey":null,"attributes":{},"defaultValue":false,"value":false,"variant":null,"reason":"ERROR","errorCode":"FLAG_NOT_FOUND","errorMessage":null,"flagMetadata":{},"configVersion":1}',
) as $message) {
    echo (strpos($logs, $message) !== false ? 'found' : 'MISSING: ' . $message) . "\n";
}
var_dump(substr_count($logs, 'Feature Flags: native evaluation ') === 102);
var_dump(strpos($logs, 'enabled; starting remote_config') === false);

// Leaving debug logging stops the native per-evaluation lines.
ini_set('datadog.trace.log_level', 'error');
$before = substr_count(read_log_snapshot($file), 'Feature Flags: native evaluation ');
$client->getBooleanValue('missing', true);
var_dump(substr_count(read_log_snapshot($file), 'Feature Flags: native evaluation ') === $before);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
found
found
found
bool(true)
bool(true)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/sdk_debug_logging.log'); ?>
