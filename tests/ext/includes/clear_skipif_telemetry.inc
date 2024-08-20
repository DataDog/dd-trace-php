<?php

dd_trace_internal_fn("finalize_telemetry");
$path = substr(ini_get("datadog.trace.agent_url"), strlen("file://"));
for ($i = 0; $i < 100; ++$i) {
    usleep(100000);
    if (file_exists($path)) {
        unlink($path);
        break;
    }
}
