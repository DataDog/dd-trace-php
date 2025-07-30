<?php

$filter = function ($name) {
    return in_array($name, ['ddtrace', 'ddtrace_injected', 'dd_library_loader']);
};

$exts = array_filter(get_loaded_extensions(false), $filter);
var_dump(array_values($exts));

$exts = array_filter(get_loaded_extensions(true), $filter);
var_dump(array_values($exts));

?>
