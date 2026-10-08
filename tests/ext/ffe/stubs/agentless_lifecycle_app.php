<?php

$root = getenv('TEST_PHP_SRCDIR');
spl_autoload_register(function ($class) use ($root) {
    if (strpos($class, 'DDTrace\\') === 0) {
        $suffix = str_replace('\\', '/', substr($class, 8)) . '.php';
        $path = $root . '/src/api/' . $suffix;
        if (!is_file($path)) $path = $root . '/src/DDTrace/' . $suffix;
        if (is_file($path)) require_once $path;
    }
});
$client = new \DDTrace\FeatureFlags\Client();
if (getenv('PHP_FFE_AGENTLESS_OPENFEATURE') === '1') {
    require $root . '/tests/OpenFeature/vendor/autoload.php';
    $api = \OpenFeature\OpenFeatureAPI::getInstance();
    $api->setProvider(new \DDTrace\OpenFeature\DataDogProvider());
    $client = $api->getClient('php-agentless-lifecycle');
}
// No testing loader or Agent supplies this configuration.
$first = $client->getStringValue('flag', 'fallback');
echo 'first=', $first, "\n";
$version = \DDTrace\ffe_config_version();
$stale = false;
$recoveredOn304 = false;
$deadline = microtime(true) + 8;
do {
    $value = $client->getStringValue('flag', 'fallback');
    $state = \DDTrace\Internal\ffe_provider_state();
    if ($value === 'green') break;
    if ($value !== 'blue') throw new RuntimeException('last known configuration lost');
    if (\DDTrace\ffe_config_version() !== $version) throw new RuntimeException('304 or malformed response changed config version');
    if ($state['reason'] === 'stale') $stale = true;
    if ($stale && $state['reason'] === 'ready') $recoveredOn304 = true;
    usleep(20000);
} while (microtime(true) < $deadline);
echo 'changed=', $value, "\n";
echo 'stale_preserved=', json_encode($stale), "\n";
echo 'recovered_on_304=', json_encode($recoveredOn304), "\n";
echo 'version_advanced_once=', json_encode(\DDTrace\ffe_config_version() === $version + 1), "\n";
echo 'source=', $state['mode'], "\n";
echo 'ready=', json_encode($state['ready']), "\n";
