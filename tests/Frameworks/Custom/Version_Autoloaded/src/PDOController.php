<?php

namespace App;

class PDOController
{
    public function render()
    {
        try {
            // We expect this to fail, but we don't care, it's just to trigger the loading of the PDOIntegration
            new \PDO("mysql:");
            echo 'This is a string';
        } finally {
            \dd_trace_internal_fn("finalize_telemetry");
        }
    }
}
