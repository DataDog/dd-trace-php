--TEST--
Thread mode: a fork child orphaned by its parent's exit replays its application on the connection to its own new listener
--SKIPIF--
<?php if (!extension_loaded('pcntl') || !extension_loaded('posix')) die('skip: pcntl and posix extensions required'); ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: thread mode not supported on Windows'); ?>
<?php if (PHP_OS === "Darwin") die("skip: kqueue fds don't survive fork() on macOS, see pcntl_fork_thread_mode_orphan.phpt"); ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip: valgrind incompatible with thread mode sidecar'); ?>
<?php if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}"); ?>
<?php require __DIR__ . '/../includes/clear_skipif_telemetry.inc' ?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=thread
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_SERVICE=orphan-telemetry-app
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
LSAN_OPTIONS=detect_leaks=0
--INI--
datadog.trace.agent_url="file://{PWD}/pcntl_fork_thread_mode_orphan-telemetry.out"
--FILE--
<?php

$parent = getmypid();
// The parent waits for the connection of the child to be served before exiting.
list($parent_end, $child_end) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$pid = pcntl_fork();
if ($pid < 0) {
    echo "Fork failed\n";
    exit(1);
}
if ($pid > 0) {
    fclose($child_end);
    fread($parent_end, 1);
    // The sidecar listener of this process ends with it, breaking the child's connection.
    exit(0);
}
// A round trip makes sure the listener of the parent serves the connection of the child.
dd_trace_internal_fn("stats_sidecar");
fclose($parent_end);
fwrite($child_end, "1");
fclose($child_end);

for ($i = 0; $i < 100 && posix_getppid() == $parent; ++$i) {
    ("us" . "leep")(50000);
}
echo posix_getppid() == $parent ? "Parent still alive\n" : "Orphaned\n";

// The first send of the request finds the connection broken: the child becomes master and
// replays its state. Sends at shutdown, like finalize_telemetry, never reconnect.
DDTrace\start_span();
DDTrace\close_span();
DDTrace\flush();

dd_trace_internal_fn("mark_integration_loaded", "orphan_integration", "1.2.3");
dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 300; ++$i) {
    ("us" . "leep")(100000);
    if (file_exists(__DIR__ . '/pcntl_fork_thread_mode_orphan-telemetry.out')) {
        foreach (file(__DIR__ . '/pcntl_fork_thread_mode_orphan-telemetry.out') as $l) {
            if ($l && $l[0] == '{') {
                $json = json_decode($l, true);
                if (!$json) {
                    continue;
                }
                $batch = $json["request_type"] == "message-batch" ? $json["payload"] : [$json];
                foreach ($batch as $message) {
                    foreach ($message["payload"]["integrations"] ?? [] as $integration) {
                        if ($integration["name"] == "orphan_integration") {
                            var_dump($json["application"]["service_name"]);
                            var_dump($integration["enabled"]);
                            break 4;
                        }
                    }
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

@unlink(__DIR__ . '/pcntl_fork_thread_mode_orphan-telemetry.out');
