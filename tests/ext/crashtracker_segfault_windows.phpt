--TEST--
Send crashtracker report when segmentation fault signal is raised and config enables it
--SKIPIF--
<?php
if (!extension_loaded('ffi')) die('skip: ffi extension required');
if (getenv('SKIP_ASAN') || getenv('USE_ZEND_ALLOC') === '0') die("skip: intentionally causes segfaults");
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %A in EXPECTF");
if (getenv('DD_TRACE_CLI_ENABLED') === '0') die("skip: tracer is disabled");
if (PHP_VERSION_ID < 70200) die("skip: TEST_PHP_EXTRA_ARGS is only available on PHP 7.2+");
if (PHP_OS_FAMILY !== 'Windows') die("skip: test only runs on Windows");
include __DIR__ . '/includes/skipif_no_dev_env.inc';
?>
--ENV--
DD_TRACE_LOG_LEVEL=0
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=3188
--INI--
datadog.trace.agent_test_session_token=tests/ext/crashtracker_segfault_windows.phpt
--FILE--
<?php

include __DIR__ . '/includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->replayRequest(); // cleanup possible leftover

usleep(100000); // Let time to the sidecar to open the crashtracker socket

$php = getenv('TEST_PHP_EXECUTABLE');
$args = getenv('TEST_PHP_ARGS')." ".getenv("TEST_PHP_EXTRA_ARGS");
$cmd = $php ." ".$args." -r \"\$f = FFI::new('char*'); \$f -= 10000000; var_dump(\$f);\"";

$ffi = FFI::cdef(
    "unsigned int SetErrorMode(unsigned int uMode);",
    "kernel32.dll"
);

// The PHP test runner sets error mode to SEM_NOGPFAULTERRORBOX, which effectively disables WER (and therefore crashtracking)
// Reenable it for this specific test
$SEM_FAILCRITICALERRORS = 0x0001;
$originalMode = $ffi->SetErrorMode($SEM_FAILCRITICALERRORS);

system($cmd);

// Restore the original error mode
$ffi->SetErrorMode($originalMode);

$rr->waitForRequest(function ($request) {
    if ($request["uri"] != "/telemetry/proxy/api/v2/apmtelemetry") {
        return false;
    }
    $body = json_decode($request["body"], true);
    if ($body["request_type"] != "logs" || !isset($body["payload"][0]["message"])) {
        return false;
    }

    foreach ($body["payload"] as $payload) {
        $payload["message"] = json_decode($payload["message"], true);
        if (!isset($payload["message"]["metadata"])) {
            break;
        }

        $output = json_encode($payload, JSON_PRETTY_PRINT);
        echo $output;

        return true;
    }

    return false;
});
?>
--EXPECTF--
%A{
    "message": {
        "data_schema_version": "1.2",
        "error": {
            "is_crash": true,
            "kind": "Panic",
            "source_type": "Crashtracking",
            "stack": {
                "format": "Datadog Crashtracker 1.0",
                "frames": [
%A
                ],
                "incomplete": false
            },
            "threads": [
%A
            ]
        },
        "incomplete": false,
        "metadata": {
            "library_name": "dd-trace-php",
            "library_version": "%s",
            "family": "php",
            "tags": [
                "is_crash:true",
                "severity:crash",
                "library_version:%s",
                "language:php",
                "runtime:php",
                "runtime-id:%s",
                "runtime_version:%s"
            ]
        },
        "os_info": {
            "architecture": "%s",
            "bitness": "%s",
            "os_type": "Windows",
            "version": "%s"
        },
        "timestamp": "%s",
        "uuid": "%s"
    },
    "level": "ERROR",
    "count": 1,
    "stack_trace": "%s",
    "tags": "%s",
    "is_sensitive": true
}%A