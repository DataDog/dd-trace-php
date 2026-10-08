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
$scenario = getenv('PHP_FFE_PROCESS_SCENARIO');
$start = microtime(true);
$first = $client->getStringValue('flag', 'fallback');
$elapsed = microtime(true) - $start;

if ($scenario === 'fork') {
    if ($first !== 'blue') throw new RuntimeException('initial fetch failed');
    $version = \DDTrace\ffe_config_version();
    $pid = pcntl_fork();
    if ($pid < 0) throw new RuntimeException('fork failed');
    // The HTTP fixture only serves green after this marker. Both copies must
    // fetch a new configuration after the split to observe it.
    echo $pid === 0 ? "child_forked\n" : "parent_forked\n";
    $deadline = microtime(true) + 7;
    $value = $first;
    do {
        // Merely evaluating would invoke first-use activation again and hide
        // a broken fork callback. Require the background poller to publish a
        // new snapshot before another evaluation is allowed to run.
        $currentVersion = \DDTrace\ffe_config_version();
        if ($currentVersion !== $version) {
            $version = $currentVersion;
            $value = $client->getStringValue('flag', 'fallback');
        }
        if ($value === 'green') break;
        if ($value !== 'blue') throw new RuntimeException('fork lost configuration');
        usleep(20000);
    } while (microtime(true) < $deadline);
    if ($value !== 'green') throw new RuntimeException('forked worker did not resume');
    echo $pid === 0 ? "child_recovered\n" : "parent_recovered\n";
    if ($pid > 0) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('child failed');
        }
        echo "fork_clean_exit\n";
    }
    exit;
}

if ($first !== 'fallback') throw new RuntimeException('unexpected initial value');
if ($elapsed < 0.15 || $elapsed > 1) throw new RuntimeException('initialization deadline not honored');
echo "initial_deadline_bounded\n";
$start = microtime(true);
for ($i = 0; $i < 10; $i++) $client->getStringValue('flag', 'fallback');
if (microtime(true) - $start > 0.5) throw new RuntimeException('initialization budget was renewed');
echo "later_evaluations_do_not_wait\n";
if ($scenario === 'shutdown') {
    // Leave a HTTP request in flight: process shutdown must cancel it without
    // waiting for the configured 30-second request timeout.
    exit;
}
$deadline = microtime(true) + 7;
do {
    $value = $client->getStringValue('flag', 'fallback');
    if ($value === 'blue') break;
    usleep(20000);
} while (microtime(true) < $deadline);
if ($value !== 'blue') throw new RuntimeException('initial failure did not recover');
echo "initial_failure_recovered\n";
