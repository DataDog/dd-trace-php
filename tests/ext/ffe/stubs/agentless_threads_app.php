<?php
require __DIR__ . '/ffe_api_bootstrap.inc';
echo "fixture_child_ready\n";
$client = new \DDTrace\FeatureFlags\Client();
if ($client->getStringValue('flag', 'fallback') !== 'blue') throw new RuntimeException('initial configuration missing');
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
        return true;
    }, array(__DIR__ . '/ffe_api_bootstrap.inc'));
}
foreach ($futures as $future) {
    $future->value();
}
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
        return true;
    });
}
foreach ($futures as $future) {
    $future->value();
}
foreach ($runtimes as $runtime) $runtime->close();
$deadline = microtime(true) + 15;
do {
    $value = $client->getStringValue('flag', 'fallback');
    if ($value === 'green') break;
    if ($value !== 'blue') throw new RuntimeException('main thread lost its configuration');
    usleep(1000);
} while (microtime(true) < $deadline);
if ($value !== 'green') throw new RuntimeException('main thread did not refresh');
echo "thread_readers_refreshed\n";
