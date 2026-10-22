<?php
// Child script for the PHP 8.4+ section of
// php2shell_mitigation_input_filter_ext_filter.phpt (ext/filter loaded). The request is a PUT, so request startup
// leaves the body alone and request_parse_body() parses it. For each variable
// it forwards, ext/filter keeps a raw copy in its INPUT_POST storage, which
// filter_has_var() and filter_input_array() read. The shutdown function runs
// after the deferred fatal, lifts memory_limit and reports what that storage
// holds. It writes to stderr, so the line does not depend on what the fatal
// does to buffered output.
$fields = (int)getenv('P2S_FIELDS');
register_shutdown_function(function () use ($fields) {
    ini_set('memory_limit', '-1');
    $all = filter_input_array(INPUT_POST, FILTER_UNSAFE_RAW);
    file_put_contents('php://stderr', 'FILTER_STORAGE'
        . ' first=' . var_export(filter_has_var(INPUT_POST, 'f0000000'), true)
        . ' last=' . var_export(filter_has_var(INPUT_POST, sprintf('f%07d', $fields - 1)), true)
        . ' count=' . (is_array($all) ? count($all) : 0) . "\n");
});
[$post, $files] = request_parse_body();
echo "SCRIPT RAN peak=", memory_get_peak_usage(true), "\n";
