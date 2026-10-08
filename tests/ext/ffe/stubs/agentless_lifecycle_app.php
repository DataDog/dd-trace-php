<?php

require __DIR__ . '/ffe_api_bootstrap.inc';
$client = new \DDTrace\FeatureFlags\Client();
if (getenv('PHP_FFE_AGENTLESS_OPENFEATURE') === '1') {
    require $root . '/tests/OpenFeature/vendor/autoload.php';
    $api = \OpenFeature\OpenFeatureAPI::getInstance();
    $api->setProvider(new \DDTrace\OpenFeature\DataDogProvider());
    $client = $api->getClient('php-agentless-lifecycle');
}
// No testing loader or Agent supplies this configuration.
echo "fixture_child_ready\n";
$first = $client->getStringValue('flag', 'fallback');
echo 'first=', $first, "\n";
$version = \DDTrace\ffe_config_version();
$stale = false;
$recoveredOn304 = false;
$deadline = microtime(true) + 30;
do {
    $before = \DDTrace\ffe_config_version();
    $value = $client->getStringValue('flag', 'fallback');
    $state = \DDTrace\Internal\ffe_provider_state();
    $after = \DDTrace\ffe_config_version();
    // These APIs are independent reads, not an atomic snapshot. A poll can
    // publish green after the blue evaluation and before the version read.
    if ($before !== $after) continue;
    if ($value === 'green') break;
    if ($value !== 'blue') throw new RuntimeException('last known configuration lost');
    if ($after !== $version) throw new RuntimeException('304 or malformed response changed config version');
    if (!$stale && $state['reason'] === 'stale') {
        $stale = true;
        echo "fixture_stale_observed\n";
    }
    if ($stale && !$recoveredOn304 && $state['reason'] === 'ready') {
        $recoveredOn304 = true;
        echo "fixture_304_observed\n";
    }
    usleep(20000);
} while (microtime(true) < $deadline);
echo 'changed=', $value, "\n";
echo 'stale_preserved=', json_encode($stale), "\n";
echo 'recovered_on_304=', json_encode($recoveredOn304), "\n";
echo 'version_advanced_once=', json_encode(\DDTrace\ffe_config_version() === $version + 1), "\n";
echo 'source=', $state['mode'], "\n";
echo 'ready=', json_encode($state['ready']), "\n";
