<?php

// Parsing and setting a seed, if provided via query string parameter 'seed'
$queries = array();
if (isset($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $queries);
}
if (isset($queries['seed'])) {
    $seed = intval($queries['seed']);
    error_log('Seed: ' . var_export($seed, 1));
    srand($seed);
}

require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/../chaos.php';
$chaos = new Chaos($allowFatalAndUncaught = true);
set_error_handler([$chaos, 'handleError']);
set_exception_handler([$chaos, 'handleException']);
$chaos->randomRequestPath();
