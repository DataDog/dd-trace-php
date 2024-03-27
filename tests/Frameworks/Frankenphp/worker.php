<?php

// Prevent worker script termination when a client connection is interrupted
ignore_user_abort(true);

$handler = static function () {
    switch ($_SERVER["REQUEST_URI"]) {
        case "/error":
            throw new \Exception("Error page");
        default:
            echo "Hello FrankenPHP!";
    }
};

for($nbRequests = 0, $running = true; isset($_SERVER['MAX_REQUESTS']) && ($nbRequests < ((int)$_SERVER['MAX_REQUESTS'])) && $running; ++$nbRequests) {
    $running = \frankenphp_handle_request($handler);
}
