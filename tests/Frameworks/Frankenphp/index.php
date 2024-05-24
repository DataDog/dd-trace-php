<?php

// Prevent worker script termination when a client connection is interrupted
ignore_user_abort(true);

$handler = static function () {
    switch (explode("?", $_SERVER["REQUEST_URI"])[0]) {
        case "/error":
            throw new \Exception("Error page");
        default:
            echo "Hello FrankenPHP!";
    }
    error_log(var_export($_SERVER, true));
};

for ($running = true; $running;) {
    $running = \frankenphp_handle_request($handler);
//    error_log(var_export(\dd_trace_serialize_closed_spans(), true));
}
