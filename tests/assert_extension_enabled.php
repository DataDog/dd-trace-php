<?php

// We use a real script as -r '<command>' does not execute the init procedure.

if (!function_exists('\DDTrace\Bridge\dd_tracing_enabled') || false === \DDTrace\Bridge\dd_tracing_enabled()) {
    echo "ddtrace extension is not loaded or it is disabled.\n";
    exit(1);
}
