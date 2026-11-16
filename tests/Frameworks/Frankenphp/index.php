<?php

// Prevent worker script termination when a client connection is interrupted
ignore_user_abort(true);

$handler = static function () {
    switch (explode("?", $_SERVER["REQUEST_URI"])[0]) {
        case "/error":
            // Worker mode does not turn a thrown exception into a 500 by itself, so set it the way
            // a real app's error handling would.
            http_response_code(500);
            throw new \Exception("Error page");
        default:
            echo "Hello FrankenPHP!";
    }
    error_log(var_export($_SERVER, true));
};

for ($running = true; $running;) {
    // A worker has to survive a request that throws. Letting the exception escape here would end
    // the worker script and make FrankenPHP spin up a replacement on every failing request, which
    // is not how a real worker-mode app behaves.
    try {
        $running = \frankenphp_handle_request($handler);
    } catch (\Throwable $e) {
        error_log("[fixture] request threw: " . $e->getMessage());
    }
//    error_log(var_export(\dd_trace_serialize_closed_spans(), true));
}
