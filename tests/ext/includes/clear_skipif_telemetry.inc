<?php

dd_trace_internal_fn("finalize_telemetry");
$path = substr(ini_get("datadog.trace.agent_url"), strlen("file://"));
for ($i = 0; $i < 100; ++$i) {
    usleep(100000);
    if (file_exists($path)) {
        // app-started can arrive before the remaining SKIPIF telemetry. Wait
        // for that lifecycle to finish before removing its records.
        foreach (file($path) as $line) {
            if (substr($line, -1) !== "\n") {
                continue;
            }
            $telemetry = json_decode($line, true);
            if (!isset(
                $telemetry["request_type"],
                $telemetry["application"]["service_name"]
            )) {
                continue;
            }
            if (in_array(
                $telemetry["application"]["service_name"],
                ["background_sender-php-service", "datadog-ipc-helper"],
                true
            )) {
                continue;
            }
            $batch = $telemetry["request_type"] === "message-batch"
                ? $telemetry["payload"] : [$telemetry];
            foreach ($batch as $event) {
                if (isset($event["request_type"])
                    && $event["request_type"] === "app-closing") {
                    unlink($path);
                    return;
                }
            }
        }
    }
}
