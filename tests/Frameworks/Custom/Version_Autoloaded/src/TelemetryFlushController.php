<?php

namespace App;

class TelemetryFlushController
{
    public function render()
    {
        // Make logs_created deterministic instead of relying on incidental startup logs.
        \DDTrace\flush();
        dd_trace_internal_fn("finalize_telemetry");
        header('Content-type: text/plain; charset=utf-8');
        echo 'This is a string';
    }
}
