<?php

require __DIR__ . '/ffe_api_bootstrap.inc';
$client = new \DDTrace\FeatureFlags\Client();
echo "fixture_child_ready\n";
if (getenv('PHP_FFE_SWOOLE_COLD') !== '1' && $client->getStringValue('flag', 'fallback') !== 'blue') {
    throw new RuntimeException('initial fetch failed');
}
$parentPid = getmypid();
$version = \DDTrace\ffe_config_version();
$server = new \Swoole\Http\Server('127.0.0.1', 0, SWOOLE_PROCESS);
$server->set(array('worker_num' => 1, 'enable_coroutine' => false, 'log_level' => SWOOLE_LOG_ERROR));
$server->on('request', function ($request, $response) { $response->end('ok'); });
$server->on('WorkerStart', function ($server) use ($client, $parentPid, $version) {
    // The worker inherits the configuration and the Remote Config reader, even without the fork hook.
    if (getmypid() === $parentPid || $client->getStringValue('flag', 'fallback') !== 'blue') {
        throw new RuntimeException('worker did not inherit the configuration');
    }
    echo "parent_forked\nchild_forked\n";
    $deadline = microtime(true) + 7;
    do {
        $value = $client->getStringValue('flag', 'fallback');
        if ($value === 'green' && \DDTrace\ffe_config_version() !== $version) break;
        if ($value !== 'blue') throw new RuntimeException('worker lost configuration');
        usleep(20000);
    } while (microtime(true) < $deadline);
    if ($value !== 'green') throw new RuntimeException('worker did not refresh');
    echo "child_recovered\n";
    $server->shutdown();
});
$server->start();
$deadline = microtime(true) + 7;
do {
    $value = $client->getStringValue('flag', 'fallback');
    if ($value === 'green') break;
    usleep(20000);
} while (microtime(true) < $deadline);
if ($value !== 'green') throw new RuntimeException('parent did not refresh');
echo "parent_recovered\nfork_clean_exit\n";
