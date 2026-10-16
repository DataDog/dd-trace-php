--TEST--
FFE SDK logs readiness and evaluation settings without claiming a disabled subscription is active
--INI--
datadog.trace.debug=1
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

// A request-local change must not be reported as enabling the startup subscription.
ini_set('datadog.experimental_flagging_provider_enabled', '1');
$client = new DDTrace\FeatureFlags\Client();
var_dump($client->getBooleanValue('missing', true, array('targetingKey' => 'secret-user')));
DDTrace\Testing\ffe_load_config('{"createdAt":"2024-01-01T00:00:00Z","environment":{"name":"test"},"flags":{}}');
var_dump($client->getBooleanValue('missing', false));
$file = __DIR__ . '/sdk_debug_logging.log';
$logs = read_log_snapshot($file);
foreach (array(
    'Feature Flags: flagging provider is disabled, so no flag configuration will be received and default values are returned; set DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true at startup (environment or php.ini) and restart',
    'Feature Flags: provider created; native evaluator is available',
    'Feature Flags: evaluation settings {',
    'Feature Flags: provider not ready; no usable Remote Configuration',
    'Feature Flags: provider ready; Remote Configuration is available',
    'Feature Flags: evaluated {"flagKey":"missing","valueType":"boolean","reason":"ERROR","variant":null,"errorCode":"PROVIDER_NOT_READY","configVersion":0}',
    'Feature Flags: evaluated {"flagKey":"missing","valueType":"boolean","reason":"ERROR","variant":null,"errorCode":"FLAG_NOT_FOUND","configVersion":1}',
) as $message) {
    echo (strpos($logs, $message) !== false ? 'found' : 'MISSING: ' . $message) . "\n";
}
var_dump(strpos($logs, 'secret-user') === false);
var_dump(strpos($logs, 'configuration source is remote_config') === false);
var_dump(strpos($logs, '"DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED":true') === false);
var_dump(strpos($logs, 'enabled; starting remote_config') === false);

// A new client can immediately use configuration already loaded in this thread.
$cachedClient = new DDTrace\FeatureFlags\Client();
var_dump(substr_count(read_log_snapshot($file), 'Feature Flags: provider ready; Remote Configuration is available') === 2);

// Default logging follows the existing DD_TRACE_DEBUG switch.
ini_set('datadog.trace.debug', '0');
DDTrace\Log\Logger::reset();
$before = substr_count(read_log_snapshot($file), 'Feature Flags:');
$quietClient = new DDTrace\FeatureFlags\Client();
var_dump($quietClient->getBooleanValue('missing', true));
var_dump(substr_count(read_log_snapshot($file), 'Feature Flags:') === $before);
?>
--EXPECT--
bool(true)
bool(false)
found
found
found
found
found
found
found
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
--CLEAN--
<?php @unlink(__DIR__ . '/sdk_debug_logging.log'); ?>
