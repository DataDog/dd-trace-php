<?php
require_once __DIR__ . '/telemetry_file.inc';

dd_trace_internal_fn("finalize_telemetry");
$path = substr(ini_get("datadog.trace.agent_url"), strlen("file://"));
for ($i = 0; $i < 100; ++$i) {
    usleep(100000);
    // app-started can arrive before the remaining SKIPIF telemetry. Wait
    // for that lifecycle to finish before removing its records.
    foreach (read_telemetry_file($path) as $event) {
        if (!isset($event["application"]["service_name"]) || in_array(
            $event["application"]["service_name"],
            ["background_sender-php-service", "datadog-ipc-helper"],
            true
        )) {
            continue;
        }
        if ($event["request_type"] === "app-closing") {
            unlink($path);
            return;
        }
    }
}
