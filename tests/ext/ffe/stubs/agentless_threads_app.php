<?php
require __DIR__ . '/ffe_api_bootstrap.inc';
echo "fixture_child_ready\n";
$client = new \DDTrace\FeatureFlags\Client();
if ($client->getStringValue('flag', 'fallback') !== 'blue') throw new RuntimeException('initial configuration missing');
$version = \DDTrace\ffe_config_version();
$runtimes = $futures = array();
for ($i = 0; $i < 8; ++$i) {
    $runtimes[$i] = new \parallel\Runtime();
    $futures[$i] = $runtimes[$i]->run(function ($bootstrap) {
        require $bootstrap;
        $client = new \DDTrace\FeatureFlags\Client();
        for ($j = 0; $j < 100; ++$j) {
            if ($client->getStringValue('flag', 'fallback') !== 'blue') throw new \RuntimeException('worker configuration missing');
            usleep(1000);
        }
        return \DDTrace\ffe_config_version();
    }, array(__DIR__ . '/ffe_api_bootstrap.inc'));
}
foreach ($futures as $future) {
    if ($future->value() !== $version) throw new RuntimeException('duplicate reader advanced shared version');
}
if (\DDTrace\ffe_config_version() !== $version) throw new RuntimeException('duplicate publication');
echo "thread_readers_shared_blue\n";
foreach ($runtimes as $i => $runtime) {
    $futures[$i] = $runtime->run(function () {
        $client = new \DDTrace\FeatureFlags\Client();
        $deadline = microtime(true) + 15;
        $green = 0;
        do {
            $value = $client->getStringValue('flag', 'fallback');
            if ($value === 'green') ++$green;
            elseif ($value !== 'blue' || $green !== 0) throw new \RuntimeException('configuration lost or reverted');
            usleep(1000);
        } while ($green < 250 && microtime(true) < $deadline);
        if ($green < 250) throw new \RuntimeException('worker did not refresh');
        return \DDTrace\ffe_config_version();
    });
}
foreach ($futures as $future) {
    if ($future->value() !== $version + 1) throw new RuntimeException('refresh advanced shared version more than once');
}
foreach ($runtimes as $runtime) $runtime->close();
if (\DDTrace\ffe_config_version() !== $version + 1) throw new RuntimeException('refresh publication count');
echo "thread_refresh_published_once\n";
