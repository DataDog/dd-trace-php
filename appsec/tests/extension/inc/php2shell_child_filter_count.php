<?php
// Child script for php2shell_mitigation_input_filter_ext_filter.phpt (the
// many-fields cases). Prints the POST field count and the peak, then, from a
// shutdown function with memory_limit lifted, whether ext/filter's INPUT_POST
// storage holds as many entries as $_POST and whether it holds the field named
// by P2S_LAST_FIELD. It writes to stderr, so the line does not depend on what
// a fatal does to buffered output.
register_shutdown_function(function () {
    ini_set('memory_limit', '-1');
    $stored = filter_input_array(INPUT_POST, FILTER_UNSAFE_RAW) ?: [];
    file_put_contents('php://stderr', 'FILTER_POST consistent='
        . var_export(count($_POST) === count($stored), true)
        . ' last=' . var_export(filter_has_var(INPUT_POST, getenv('P2S_LAST_FIELD')), true)
        . "\n");
});
echo "SCRIPT RAN POST post_fields=", count($_POST),
    " peak=", memory_get_peak_usage(true), "\n";
