<?php

dd_trace('require_once', function() {
    $args = func_get_args();
    error_log("Called require_once: " . print_r(, 1));
    return call_user_func_array('require_once', $args);
});

require_once __DIR__ . '/vendor/autoload.php';

