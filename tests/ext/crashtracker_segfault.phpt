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
--INI--
datadog.trace.agent_test_session_token=tests/ext/crashtracker_segfault.phpt
--FILE--
<?php

include __DIR__ . '/includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->replayRequest(); // cleanup possible leftover

usleep(100000); // Let time to the sidecar to open the crashtracker socket

posix_setrlimit(POSIX_RLIMIT_CORE, 0, 0);

$php = getenv('TEST_PHP_EXECUTABLE');
$args = getenv('TEST_PHP_ARGS')." ".getenv("TEST_PHP_EXTRA_ARGS");
$cmd = $php." ".$args." -r 'spl_autoload_register(function() { posix_kill(posix_getpid(), 11); }); class_exists(Test::class);'";
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
            if (!isset($payload["message"]["metadata"])) {
                break;
            }
            if (($payload["message"]["kind"] ?? "") == "Crash ping") {
                continue;
            }

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
            "runtime_stack": {
                "format": "Datadog Runtime Callback 1.0",
                "frames": [
                    {
                        "file": "[internal function]",
                        "function": "posix_kill"
                    },
                    {
                        "file": "Command line code",
                        "function": "{closur%s}",
                        "line": 1
                    },
%A                  {
                        "file": "[internal function]",
                        "function": "class_exists",
                        "line": 1
                    },
                    {
                        "file": "Command line code",
                        "function": "[top-level code]",
                        "line": 1
                    }
                ]
            }
%A
        "files": {
%A
        },
        "incomplete": false,
        "metadata": {
            "library_name": "dd-trace-php",
            "library_version": "%s",
            "family": "php",
            "tags": [
%A
            ]
        },
        "os_info": {
%A
        },
        "timestamp": "%s",
        "uuid": "%s"
    },
    "level": "ERROR",
    "count": 1,
    "stack_trace": %s,
    "tags": "%ssi_signo_human_readable:SIGSEGV%S",
    "is_sensitive": true,
    "is_crash": true
}%A
