--TEST--
Thread mode: a fork child orphaned by its parent's exit replays its application on the connection to its own new listener
--SKIPIF--
<?php if (!extension_loaded('pcntl') || !extension_loaded('posix')) die('skip: pcntl and posix extensions required'); ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: thread mode not supported on Windows'); ?>
<?php if (PHP_OS === "Darwin") die("skip: kqueue fds don't survive fork() on macOS, see pcntl_fork_thread_mode_orphan.phpt"); ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip: valgrind incompatible with thread mode sidecar'); ?>
<?php if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}"); ?>
<?php
// Remove stale records before the FILE process starts, including records from a reused PID.
// Doing this in FILE could erase the current parent's only readiness request.
foreach (glob(__DIR__ . '/pcntl_fork_thread_mode_orphan-telemetry/telemetry-*') as $file) {
    // The SKIPIF listener may already be renaming its own temporary request files.
    if (!@unlink($file) && file_exists($file)) {
        die("Failed to remove stale telemetry record: $file");
    }
}
?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=thread
DD_SERVICE=orphan-telemetry-app
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
LSAN_OPTIONS=detect_leaks=0
--INI--
datadog.trace.agent_url="file://{PWD}/pcntl_fork_thread_mode_orphan-telemetry/"
--FILE--
<?php

$parent = getmypid();
// Complete the parent's first request before forking, so the child cannot inherit an
// in-progress initialization of the entity-ID request headers. This test exercises
// reconnecting after an established listener exits, not racing its startup.
$parentRequests = __DIR__ . '/pcntl_fork_thread_mode_orphan-telemetry/telemetry-*-' . $parent . '-*.json';
for ($i = 0; $i < 100 && !glob($parentRequests); ++$i) {
    ("us" . "leep")(100000);
}
if (!glob($parentRequests)) {
    echo "The parent's sidecar never completed its first request\n";
    exit(1);
}

// The parent waits for the connection of the child to be served before exiting.
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($pair === false) {
    echo "Creating the parent-child socket pair failed\n";
    exit(1);
}
list($parent_end, $child_end) = $pair;
if (!stream_set_timeout($parent_end, 15) || !stream_set_timeout($child_end, 15)) {
    echo "Setting the parent-child socket timeout failed\n";
    exit(1);
}
$pid = pcntl_fork();
if ($pid < 0) {
    echo "Fork failed\n";
    exit(1);
}
if ($pid > 0) {
    fclose($child_end);
    $ack = fread($parent_end, 1);
    $metadata = stream_get_meta_data($parent_end);
    fclose($parent_end);
    if ($ack !== "1") {
        echo $metadata["timed_out"]
            ? "Timed out waiting for the child's connection acknowledgement\n"
            : "The child's connection acknowledgement was not received\n";
        // A stuck round trip must not leave an orphan running after the test fails.
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        exit(1);
    }
    // The sidecar listener of this process ends with it, breaking the child's connection.
    exit(0);
}
fclose($parent_end);
// A successful round trip makes sure the parent serves the child's connection before exiting.
$stats = dd_trace_internal_fn("stats_sidecar");
if (!is_string($stats) || !is_array(json_decode($stats, true))) {
    echo "The child's sidecar round trip failed\n";
    fclose($child_end);
    exit(1);
}
if (fwrite($child_end, "1") !== 1) {
    echo "Sending the child's connection acknowledgement failed\n";
    fclose($child_end);
    exit(1);
}
fclose($child_end);

$deadline = microtime(true) + 15;
while (posix_getppid() == $parent && microtime(true) < $deadline) {
    ("us" . "leep")(50000);
}
if (posix_getppid() == $parent) {
    echo "Timed out waiting for the parent to exit\n";
    exit(1);
}
echo "Orphaned\n";

// The first send of the request finds the connection broken: the child becomes master and
// replays its state. Sends at shutdown, like finalize_telemetry, never reconnect. A metric goes to
// DogStatsD, without submitting trace data to the telemetry output directory.
DDTrace\dogstatsd_count("orphan.reconnect", 1);

// Only this child's telemetry may satisfy the test, not SKIPIF or stale records from a prior run.
$integrationName = uniqid("orphan_integration_", true);
dd_trace_internal_fn("mark_integration_loaded", $integrationName, "1.2.3");
dd_trace_internal_fn("finalize_telemetry");

// Directory output avoids the single-file recorder's mutex, which a fork can inherit locked.
// Each request is renamed from .json.tmp to .json once complete, so ignore temporary files.
for ($i = 0; $i < 300; ++$i) {
    ("us" . "leep")(100000);
    foreach (glob(__DIR__ . '/pcntl_fork_thread_mode_orphan-telemetry/telemetry-*.json') as $file) {
        $json = json_decode(file_get_contents($file), true);
        if (!isset($json["request_type"])) {
            continue;
        }
        $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
        foreach ($batch as $message) {
            foreach ($message["payload"]["integrations"] ?? [] as $integration) {
                if ($integration["name"] == $integrationName) {
                    var_dump($json["application"]["service_name"]);
                    var_dump($integration["enabled"]);
                    break 4;
                }
            }
        }
    }
}
if ($i == 300) {
    echo "The integration of the orphaned child never arrived\n";
}

?>
--EXPECTF--
%AOrphaned
%Apromoting to master%A
string(20) "orphan-telemetry-app"
bool(true)
%A
--CLEAN--
<?php

foreach (glob(__DIR__ . '/pcntl_fork_thread_mode_orphan-telemetry/telemetry-*') as $file) {
    @unlink($file);
}
@rmdir(__DIR__ . '/pcntl_fork_thread_mode_orphan-telemetry');
