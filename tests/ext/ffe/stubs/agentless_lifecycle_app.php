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
$retained = false;
stream_set_blocking(STDIN, false);
$recoveredOn304 = false;
$deadline = microtime(true) + 30;
do {
    $before = \DDTrace\ffe_config_version();
    $value = $client->getStringValue('flag', 'fallback');
    $state = \DDTrace\Internal\ffe_provider_state();
    $after = \DDTrace\ffe_config_version();
    // A concurrent replacement may change the version, but must never expose
    // an empty configuration between the old and new accepted values.
    if ($value !== 'blue' && $value !== 'green') {
        throw new RuntimeException('configuration missing during CDN replacement');
    }
    // These APIs are independent reads, not an atomic snapshot. A poll can
    // publish green after the blue evaluation and before the version read.
    if ($before !== $after) continue;
    if ($value === 'green') break;
    if ($value !== 'blue') throw new RuntimeException('last known configuration lost');
    if ($after !== $version) throw new RuntimeException('304 or malformed response changed config version');
    $phase = trim((string) fgets(STDIN));
    if (!$retained && $phase === '3') {
        $retained = $state['ready'] && $value === 'blue' && $after === $version;
        echo "fixture_invalid_retained\n";
    }
    if ($retained && !$recoveredOn304 && $phase === '4' && $state['ready']) {
        $recoveredOn304 = true;
        echo "fixture_304_observed\n";
    }
    usleep(20000);
} while (microtime(true) < $deadline);
echo 'changed=', $value, "\n";
echo 'last_good_preserved=', json_encode($retained), "\n";
echo 'unchanged_on_304=', json_encode($recoveredOn304), "\n";
echo 'version_advanced_once=', json_encode(\DDTrace\ffe_config_version() === $version + 1), "\n";
echo 'source=', $state['mode'], "\n";
echo 'ready=', json_encode($state['ready']), "\n";
