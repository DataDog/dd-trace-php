--TEST--
Thread mode sidecar connection sends traces (CLI with fallback to subprocess)
--SKIPIF--
<?php include __DIR__ . '/includes/skipif_no_dev_env.inc'; ?>
<?php if (PHP_VERSION_ID < 70000) die('skip: PHP 7.0+ required'); ?>
<?php if (PHP_OS_FAMILY === 'Windows') die('skip: Thread mode not supported on Windows'); ?>
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_SIDECAR_CONNECTION_MODE=auto
--INI--
datadog.trace.agent_test_session_token=sidecar_thread_functional_test
--FILE--
<?php
include __DIR__ . '/includes/request_replayer.inc';

/*
 * Functional test for thread mode sidecar connection
 *
 * Note: In CLI, thread mode cannot fully initialize (requires PHP-FPM master/worker),
 * but auto mode falls back to subprocess. This test verifies:
 * 1. Sidecar connection works (subprocess or thread)
 * 2. Traces are successfully sent through the sidecar
 * 3. The fallback mechanism works correctly
 *
 * For full thread mode testing with PHP-FPM, see:
 * - tests/ext/sidecar_connection_mode_thread_phpfpm_manual.md
 * - tests/ext/test_phpfpm_thread_mode.sh
 */

$rr = new RequestReplayer();

// Cleanup any leftover requests
$rr->replayRequest();

echo "Connection mode: " . ini_get('datadog.trace.sidecar_connection_mode') . "\n";

// Create a span that should be sent via sidecar
DDTrace\start_span();
DDTrace\active_span()->name = 'thread.mode.test';
DDTrace\active_span()->service = 'thread-mode-functional-test';
DDTrace\active_span()->resource = 'test_resource';
DDTrace\active_span()->meta['test.key'] = 'test.value';
DDTrace\close_span();

echo "Span created\n";

// Wait for trace to be sent and retrieve it
$trace_data = $rr->waitForDataAndReplay();

echo "Trace received\n";

// Parse the trace
$decoded = json_decode($trace_data["body"], true);

// Handle both chunked and non-chunked formats
$spans = $decoded["chunks"][0]["spans"] ?? $decoded[0];

if (!is_array($spans) || empty($spans)) {
    die("FAIL: No spans in trace\n");
}

$root_span = $spans[0];

// Verify span properties
if ($root_span["name"] !== "thread.mode.test") {
    die("FAIL: Expected span name 'thread.mode.test', got: " . $root_span["name"] . "\n");
}

if ($root_span["service"] !== "thread-mode-functional-test") {
    die("FAIL: Expected service 'thread-mode-functional-test', got: " . $root_span["service"] . "\n");
}

if ($root_span["resource"] !== "test_resource") {
    die("FAIL: Expected resource 'test_resource', got: " . $root_span["resource"] . "\n");
}

if (!isset($root_span["meta"]["test.key"]) || $root_span["meta"]["test.key"] !== "test.value") {
    die("FAIL: Expected meta tag 'test.key' = 'test.value'\n");
}

echo "Span properties verified\n";
echo "Test passed: Sidecar connection works and traces are sent\n";

?>
--EXPECTF--
Connection mode: auto
%a
Span created
Trace received
Span properties verified
Test passed: Sidecar connection works and traces are sent
%a
