--TEST--
Send crashtracker report when segmentation fault signal is raised and config enables it
--SKIPIF--
<?php
if (!extension_loaded('posix')) die('skip: posix extension required');
if (getenv('SKIP_ASAN') || getenv('USE_ZEND_ALLOC') === '0') die("skip: intentionally causes segfaults");
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %A in EXPECTF");
if (getenv('DD_TRACE_CLI_ENABLED') === '0') die("skip: tracer is disabled");
if (PHP_VERSION_ID < 70200) die("skip: TEST_PHP_EXTRA_ARGS is only available on PHP 7.2+");
include __DIR__ . '/includes/skipif_no_dev_env.inc';
?>
--ENV--
DD_TRACE_LOG_LEVEL=0
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_CRASHTRACKING_ENABLED=0
--INI--
datadog.trace.agent_test_session_token=tests/ext/crashtracker_segfault.phpt
--FILE--
<?php

include __DIR__ . '/includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->replayRequest(); // cleanup possible leftover

usleep(100000); // Let time to the sidecar to open the crashtracker socket

$php = getenv('TEST_PHP_EXECUTABLE');
$args = getenv('TEST_PHP_ARGS')." ".getenv("TEST_PHP_EXTRA_ARGS");
$cmd = $php." ".$args." -r 'posix_kill(posix_getpid(), 11);'";
system($cmd);

$rr->waitForRequest(function ($request) {
    if ($request["uri"] != "/telemetry/proxy/api/v2/apmtelemetry") {
        return false;
    }
    $body = json_decode($request["body"], true);
    if ($body["request_type"] != "logs" || !isset($body["payload"][0]["message"])) {
        return false;
    }

    $payload = $body["payload"][0];
    $payload["message"] = json_decode($payload["message"], true);
    $output = json_encode($payload, JSON_PRETTY_PRINT);

    echo $output;

    return true;
});

?>
--EXPECTF--
%A
Fatal error: Uncaught Exception: wait for replay timeout in %s
%A
