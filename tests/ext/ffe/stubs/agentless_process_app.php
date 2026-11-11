<?php

require __DIR__ . '/ffe_api_bootstrap.inc';
$client = new \DDTrace\FeatureFlags\Client();
$scenario = getenv('PHP_FFE_PROCESS_SCENARIO');
$instrumented = getenv('USE_ZEND_ALLOC') === '0';
echo "fixture_child_ready\n";
$start = microtime(true);
$first = $client->getStringValue('flag', 'fallback');
$elapsed = microtime(true) - $start;

if ($scenario === 'isolation') {
    if ($first !== 'blue') throw new RuntimeException('configuration did not reach the PHP evaluator');
    echo "cdn_configuration_evaluated\n";
    exit;
}

if ($scenario === 'retarget') {
    if ($first !== 'blue') throw new RuntimeException('initial fetch failed');
    foreach (array('datadog.service', 'datadog.env', 'datadog.version') as $setting) {
        if (ini_set($setting, 'changed-target') === false) throw new RuntimeException('cannot change target');
        // Target replacement must not clear the process-stable CDN snapshot
        // while the new combined RC directory is still being published.
        if (!\DDTrace\ffe_has_config()) throw new RuntimeException('target change cleared CDN flags');
        if ($client->getStringValue('flag', 'fallback') !== 'blue') throw new RuntimeException('target change lost CDN flags');
    }
    echo "configuration_retained_after_target_changes\n";
    $deadline = microtime(true) + 7;
    do {
        $value = $client->getStringValue('flag', 'fallback');
        if ($value === 'green') break;
        if ($value !== 'blue') throw new RuntimeException('target change lost configuration during refresh');
        usleep(20000);
    } while (microtime(true) < $deadline);
    if ($value !== 'green') throw new RuntimeException('new target did not receive replacement');
    echo "replacement_received_after_target_changes\n";
    exit;
}

if ($scenario === 'restart') {
    // Establish a last-good snapshot before testing its survival. Instrumented
    // subprocess startup may exceed the first-use budget and legitimately
    // return the default; initialization timing is covered by recovery/shutdown.
    $readyDeadline = microtime(true) + ($instrumented ? 20 : 5);
    while ($first !== 'blue' && microtime(true) < $readyDeadline) {
        usleep(20000);
        $first = $client->getStringValue('flag', 'fallback');
    }
    if ($first !== 'blue') throw new RuntimeException('initial configuration unavailable: ' . json_encode(\DDTrace\Internal\ffe_provider_state()));
    echo "fixture_restart_ready\n";
    stream_set_timeout(STDIN, $instrumented ? 20 : 5);
    if (fgets(STDIN) !== "sidecar_killed\n") throw new RuntimeException('fixture did not stop the sidecar');
    if ($client->getStringValue('flag', 'fallback') !== 'blue') throw new RuntimeException('sidecar loss dropped the configuration');
    echo "last_configuration_retained\n";
    $deadline = microtime(true) + 12;
    do {
        // Sidecar traffic detects the lost connection; reconnecting replays the CDN registration.
        \DDTrace\Internal\flush_ffe_evaluation_metrics();
        $value = $client->getStringValue('flag', 'fallback');
        if ($value === 'green') break;
        if ($value !== 'blue') throw new RuntimeException('sidecar restart lost configuration');
        usleep(20000);
    } while (microtime(true) < $deadline);
    if ($value !== 'green') throw new RuntimeException('sidecar restart did not recover');
    echo "sidecar_restarted_and_refreshed\n";
    // Keep PHP and its IPC connection alive while the fixture signals the
    // replacement daemon; an idle exit would hide an inherited blocked mask.
    stream_set_timeout(STDIN, $instrumented ? 20 : 5);
    if (fgets(STDIN) !== "sidecar_terminated\n") throw new RuntimeException('replacement sidecar did not handle SIGTERM');
    echo "sidecar_sigterm_handled\n";
    exit;
}

if ($scenario === 'fork') {
    if ($first !== 'blue') throw new RuntimeException('initial fetch failed');
    $version = \DDTrace\ffe_config_version();
    $pid = pcntl_fork();
    if ($pid < 0) throw new RuntimeException('fork failed');
    // The HTTP fixture only serves green after this marker. Both copies must
    // read a new configuration after the split to observe it.
    echo $pid === 0 ? "child_forked\n" : "parent_forked\n";
    $deadline = microtime(true) + 7;
    $value = $first;
    do {
        // With RC enabled, require the ordinary notification/VM-interrupt
        // path to publish before evaluation. Without notifications, evaluation
        // itself consumes the shared-memory update; no helper thread is needed.
        $currentVersion = \DDTrace\ffe_config_version();
        if (getenv('DD_REMOTE_CONFIG_ENABLED') !== '1' || $currentVersion !== $version) {
            $version = $currentVersion;
            $value = $client->getStringValue('flag', 'fallback');
        }
        if ($value === 'green') break;
        if ($value !== 'blue') throw new RuntimeException('fork lost configuration');
        usleep(20000);
    } while (microtime(true) < $deadline);
    if ($value !== 'green') throw new RuntimeException('forked process did not refresh');
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
if ($elapsed < ($instrumented ? 1.5 : 0.15) || $elapsed > ($instrumented ? 10 : 1)) throw new RuntimeException('initialization deadline not honored: elapsed=' . $elapsed);
echo "initial_deadline_bounded\n";
$start = microtime(true);
for ($i = 0; $i < 10; $i++) $client->getStringValue('flag', 'fallback');
if (microtime(true) - $start > ($instrumented ? 3 : 0.5)) throw new RuntimeException('initialization budget was renewed: elapsed=' . (microtime(true) - $start));
echo "later_evaluations_do_not_wait\n";
if ($scenario === 'shutdown') {
    // The initialization deadline can expire before the worker is scheduled.
    // Confirm the fixture has a request before measuring its cancellation.
    stream_set_timeout(STDIN, $instrumented ? 20 : 5);
    if (fgets(STDIN) !== "request_received\n") {
        throw new RuntimeException('fixture did not observe an in-flight request');
    }
    // Shutdown must not wait for the configured 30-second request timeout.
    exit;
}
$deadline = microtime(true) + ($instrumented ? 20 : 7);
do {
    $value = $client->getStringValue('flag', 'fallback');
    if ($value === 'blue') break;
    usleep(20000);
} while (microtime(true) < $deadline);
if ($value !== 'blue') throw new RuntimeException('initial failure did not recover');
echo "initial_failure_recovered\n";
