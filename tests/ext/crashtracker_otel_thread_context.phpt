--TEST--
Crashtracker collects OTel thread context from the crashing process
--SKIPIF--
<?php
die('skip: otel-thread-ctx feature temporarily disabled');
if (!extension_loaded('posix')) die('skip: posix extension required');
if (getenv('SKIP_ASAN') || getenv('USE_ZEND_ALLOC') === '0') die("skip: intentionally causes segfaults");
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %A in EXPECTF");
if (getenv('DD_TRACE_CLI_ENABLED') === '0') die("skip: tracer is disabled");
if (PHP_VERSION_ID < 70400) die("skip: FFI requires PHP 7.4+");
if (PHP_OS_FAMILY !== 'Linux') die('skip: Linux only');
if (!extension_loaded('ffi')) die('skip: ffi extension required');
include __DIR__ . '/includes/skipif_no_dev_env.inc';
?>
--ENV--
DD_TRACE_LOG_LEVEL=0
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_SERVICE=crashtest-service
DD_ENV=crashtest-env
DD_VERSION=crashtest-version
--INI--
datadog.trace.agent_test_session_token=tests/ext/crashtracker_otel_thread_context.phpt
--FILE--
<?php

include __DIR__ . '/includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->replayRequest(); // cleanup possible leftover

usleep(100000); // Let time to the sidecar to open the crashtracker socket

posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0);

// Write the crashing PHP script to a temp file.
// It starts a root span (populating otel_thread_ctx_v1) then crashes.
$script_path = sys_get_temp_dir() . '/ct_otel_ctx_' . getmypid() . '.php';
file_put_contents($script_path, '<?php
$root = DDTrace\start_span();
$root->name = "crash.span";
// Crash while the span is active so the OTel thread context is populated
posix_kill(posix_getpid(), 11);
');

$php = getenv('TEST_PHP_EXECUTABLE');
$args = getenv('TEST_PHP_EXTRA_ARGS');
system("$php $args $script_path");

$rr->waitForRequest(function ($request) {
    if ($request["uri"] != "/telemetry/proxy/api/v2/apmtelemetry") {
        return false;
    }
    $body = json_decode($request["body"], true);
    $batch = $body["request_type"] == "message-batch"
        ? $body["payload"] : [$body];

    foreach ($batch as $json) {
        if ($json["request_type"] != "logs"
            || !isset($json["payload"]["logs"])) {
            continue;
        }

        foreach ($json["payload"]["logs"] as $payload) {
            $payload["message"] = json_decode(
                $payload["message"], true
            );
            if (!isset($payload["message"]["metadata"])) {
                break;
            }
            if (($payload["is_crash"] ?? false) !== true) {
                continue;
            }

            $experimental = $payload["message"]["experimental"] ?? [];
            $contexts = $experimental["otel_thread_contexts"] ?? [];

            if (empty($contexts)) {
                echo "otel_thread_contexts: MISSING\n";
                return true;
            }

            // Find the context for the crashing (main) thread
            $ctx = $contexts[0];
            echo "has_otel_thread_contexts: yes\n";
            echo "has_trace_id: ",
                (preg_match('/^[0-9a-f]{32}$/', $ctx["trace_id"])
                    ? "yes" : "no"), "\n";
            echo "has_span_id: ",
                (preg_match('/^[0-9a-f]{16}$/', $ctx["span_id"])
                    ? "yes" : "no"), "\n";
            echo "trace_id_nonzero: ",
                ($ctx["trace_id"] !== str_repeat('0', 32)
                    ? "yes" : "no"), "\n";
            echo "span_id_nonzero: ",
                ($ctx["span_id"] !== str_repeat('0', 16)
                    ? "yes" : "no"), "\n";

            // Check attributes contain service, env, version
            $attrs = [];
            foreach ($ctx["attributes"] ?? [] as $attr) {
                $attrs[$attr["key_index"]] = $attr["value"];
            }
            echo "service: ", ($attrs[1] ?? ""), "\n";
            echo "env: ", ($attrs[2] ?? ""), "\n";
            echo "version: ", ($attrs[3] ?? ""), "\n";

            return true;
        }
    }

    return false;
});

@unlink($script_path);
?>
--EXPECTF--
%Ahas_otel_thread_contexts: yes
has_trace_id: yes
has_span_id: yes
trace_id_nonzero: yes
span_id_nonzero: yes
service: crashtest-service
env: crashtest-env
version: crashtest-version
