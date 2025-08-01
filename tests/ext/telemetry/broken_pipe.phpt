--TEST--
Telemetry test with connection reset
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support {PWD}");
if (PHP_OS === "WINNT" && PHP_VERSION_ID < 70400) die("skip: Windows on PHP 7.2 and 7.3 have permission issues with synchronous access to telemetry");
if (getenv('USE_ZEND_ALLOC') === '0' && !getenv("SKIP_ASAN")) die('skip timing sensitive test - valgrind is too slow');
require __DIR__ . '/../includes/clear_skipif_telemetry.inc'
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_TRACE_LOG_LEVEL=info,startup=off
--INI--
datadog.trace.agent_url="file://{PWD}/broken_pipe-telemetry.out"
--FILE--
<?php

DDTrace\start_span();
$root = DDTrace\root_span();

$root->service = 'broken_pipe-telemetry-app';
$root->env = 'test-env';

DDTrace\close_span();

// At this stage, the service and env are stored to be used by telemetry
dd_trace_serialize_closed_spans();

// force a reconnect, it needs to resubmit telemetry info
dd_trace_internal_fn("break_sidecar_connection");

dd_trace_internal_fn("finalize_telemetry");

for ($i = 0; $i < 300; ++$i) {
    usleep(100000);
    if (file_exists(__DIR__ . '/broken_pipe-telemetry.out')) {
        $batches = [];
        foreach (file(__DIR__ . '/broken_pipe-telemetry.out') as $l) {
            if ($l) {
                $json = json_decode($l, true);
                if ($json["application"]["service_name"] == "background_sender-php-service" || $json["application"]["service_name"] == "datadog-ipc-helper") {
                    continue;
                }
                array_push($batches, ...($json["request_type"] == "message-batch" ? $json["payload"] : [$json]));
            }
        }
        $found = array_filter($batches, function ($json) {
            return ($json["request_type"] == "app-started" && $json["application"]["service_name"] == "broken_pipe-telemetry-app")
                    || $json["request_type"] == "app-closing"
                    || $json["request_type"] == "app-client-configuration-change";
        });
        if (count($found) == 3) {
            foreach ($found as $json) {
                $request_type = $json['request_type'];
                var_dump($json["request_type"]);

                if ($request_type == 'app-started') {
                    var_dump($json['application']['service_name']);
                    var_dump($json['application']['env']);
                }
            }
            break;
        }
    }
}

?>
--EXPECTF--
[ddtrace] [info] Flushing trace of size 1 to send-queue for %sbroken_pipe-telemetry.out
[ddtrace] [datadog_sidecar::service::blocking] The sidecar transport is closed. Reconnecting... This generally indicates a problem with the sidecar, most likely a crash. Check the logs / core dump locations and possibly report a bug.
string(11) "app-started"
string(25) "broken_pipe-telemetry-app"
string(8) "test-env"
string(31) "app-client-configuration-change"
string(11) "app-closing"
[ddtrace] [info] No finished traces to be sent to the agent
--CLEAN--
<?php

@unlink(__DIR__ . '/broken_pipe-telemetry.out');
