<?php
// Child script for the request_parse_body() tests (PHP 8.4+).
// The request is a PUT, so request startup does not parse the body; it is
// parsed here, by request_parse_body(), which calls sapi_handle_post() directly
// instead of going through sapi_module.treat_data. P2S_RPB_POST_MAX_SIZE, if
// set, is passed as the post_max_size option under the key P2S_RPB_KEY
// (default "post_max_size"). An integer value is passed as an int, anything
// else as a string. If it is not set, request_parse_body() gets no argument.
echo "BEFORE PARSE post_fields=", count($_POST),
    " usage=", memory_get_usage(true), "\n";
$opts = [];
$v = getenv('P2S_RPB_POST_MAX_SIZE');
if ($v !== false) {
    $opts[getenv('P2S_RPB_KEY') ?: 'post_max_size'] =
        preg_match('/^-?\d+$/', $v) ? (int)$v : $v;
}
[$post, $files] = $opts ? request_parse_body($opts) : request_parse_body();
echo "SCRIPT RAN",
    " post_fields=", count($post),
    " files=", count($files),
    " peak=", memory_get_peak_usage(true),
    "\n";
