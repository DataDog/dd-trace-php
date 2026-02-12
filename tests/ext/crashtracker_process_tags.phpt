--TEST--
[crasht] process tags are attached to crash report
--SKIPIF--
<?php
if (!extension_loaded('posix')) die('skip: posix extension required');
if (getenv('SKIP_ASAN') || getenv('USE_ZEND_ALLOC') === '0') die("skip: intentionally causes segfaults");
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %A in EXPECTF");
if (getenv('DD_TRACE_CLI_ENABLED') === '0') die("skip: tracer is disabled");
include __DIR__ . '/includes/skipif_no_dev_env.inc';
?>
--ENV--
DD_TRACE_LOG_LEVEL=0
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_EXPERIMENTAL_PROPAGATE_PROCESS_TAGS_ENABLED=1
--INI--
datadog.trace.agent_test_session_token=tests/ext/crashtracker_process_tags.phpt
--FILE--
<?php

include __DIR__ . '/includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->replayRequest(); // cleanup possible leftover

usleep(100000); // Let time to the sidecar to open the crashtracker socket

posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0);

$php = getenv('TEST_PHP_EXECUTABLE');
$args = getenv('TEST_PHP_ARGS')." ".getenv("TEST_PHP_EXTRA_ARGS");
$cmd = $php." ".$args." -r 'posix_kill(posix_getpid(), 11);'";
system($cmd);

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
            $output = json_encode($payload, JSON_PRETTY_PRINT);

            echo $output;

            return true;
        }
    }

    return false;
});
?>
--EXPECTF--
%A{
    "message": {
%A
        "metadata": {
            "library_name": "dd-trace-php",
            "library_version": "%s",
            "family": "php",
            "tags": [%A
                "process_tags:entrypoint.name:standard_input_code,entrypoint.type:script,entrypoint.workdir:%s,runtime.sapi:cli"%A
}%A
