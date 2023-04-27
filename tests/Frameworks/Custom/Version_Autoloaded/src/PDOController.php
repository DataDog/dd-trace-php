<?php

namespace App;

class PDOController
{
    public function render()
    {
        try {
            new \PDO("mysql:");
            echo 'This is a string';
        } finally {
            \dd_trace_internal_fn("finalize_telemetry");
        }
    }
}
