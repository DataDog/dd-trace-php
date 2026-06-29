--TEST--
Crashtracker collects all threads when collect_all_threads is enabled
--SKIPIF--
<?php
if (!extension_loaded('posix')) die('skip: posix extension required');
if (getenv('SKIP_ASAN') || getenv('USE_ZEND_ALLOC') === '0') die("skip: intentionally causes segfaults");
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %A in EXPECTF");
if (getenv('DD_TRACE_CLI_ENABLED') === '0') die("skip: tracer is disabled");
if (PHP_VERSION_ID < 70200) die("skip: TEST_PHP_EXTRA_ARGS is only available on PHP 7.2+");
if (!extension_loaded('ffi')) die('skip: ffi extension required');
if (!trim(shell_exec('which cc 2>/dev/null') ?: shell_exec('which gcc 2>/dev/null') ?: '')) die('skip: C compiler not available');
include __DIR__ . '/includes/skipif_no_dev_env.inc';
?>
--ENV--
DD_TRACE_LOG_LEVEL=0
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
--INI--
datadog.trace.agent_test_session_token=tests/ext/crashtracker_collect_all_threads.phpt
--FILE--
<?php

include __DIR__ . '/includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->replayRequest(); // cleanup possible leftover

usleep(100000); // Let time to the sidecar to open the crashtracker socket

posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0);

// Build a tiny C shared library that spawns sleeping pthreads.
// Using pure C avoids PHP ZTS/NTS thread-safety concerns entirely.
$so_path = sys_get_temp_dir() . '/ct_thread_helper_' . getmypid() . '.so';
$src_path = $so_path . '.c';

file_put_contents($src_path, '
#include <pthread.h>
#include <unistd.h>

static void *sleeper(void *arg) { sleep(3600); return NULL; }

void ct_spawn_threads(int n) {
    pthread_t t;
    int i;
    for (i = 0; i < n; i++) {
        pthread_create(&t, NULL, sleeper, NULL);
        pthread_detach(t);
    }
    usleep(50000); /* give threads time to be scheduled */
}
');

$cc = trim(shell_exec('which cc 2>/dev/null') ?: shell_exec('which gcc 2>/dev/null') ?: '');
shell_exec("$cc -shared -fPIC -o $so_path $src_path -lpthread 2>/dev/null");

// Write the crashing PHP script to a temp file so we can load the helper .so
$script_path = sys_get_temp_dir() . '/ct_crash_test_' . getmypid() . '.php';
$so_path_esc = addslashes($so_path);
file_put_contents($script_path, '<?php
try {
    $ffi = FFI::cdef("void ct_spawn_threads(int n);", "' . $so_path_esc . '");
    $ffi->ct_spawn_threads(3);
} catch (Throwable $e) { /* FFI unavailable or compile failed – crash anyway */ }
spl_autoload_register(function() { posix_kill(posix_getpid(), 11); });
class_exists(Test::class);
');

$php = getenv('TEST_PHP_EXECUTABLE');
$args = getenv('TEST_PHP_ARGS') . " " . getenv("TEST_PHP_EXTRA_ARGS");
system("$php $args $script_path");

$rr->waitForRequest(function ($request) {
    if ($request["uri"] != "/telemetry/proxy/api/v2/apmtelemetry") {
        return false;
    }
    $body = json_decode($request["body"], true);
    $batch = $body["request_type"] == "message-batch" ? $body["payload"] : [$body];

    foreach ($batch as $json) {
        if ($json["request_type"] != "logs" || !isset($json["payload"]["logs"])) {
            continue;
        }

        foreach ($json["payload"]["logs"] as $payload) {
            $payload["message"] = json_decode($payload["message"], true);
            if (!isset($payload["message"]["metadata"])) {
                break;
            }
            if (($payload["is_crash"] ?? false) !== true) {
                continue;
            }

            $error   = $payload["message"]["error"] ?? null;
            $threads = $error["threads"] ?? null;
            $count   = is_array($threads) ? count($threads) : 0;

            echo "collect_all_threads enabled: ", ($count > 0 ? "yes" : "no"), PHP_EOL;
            echo "thread count: ", $count, PHP_EOL;

            return true;
        }
    }

    return false;
});

// Cleanup temp files
@unlink($src_path);
@unlink($so_path);
@unlink($script_path);
?>
--EXPECTF--
%Acollect_all_threads enabled: yes
thread count: %d
