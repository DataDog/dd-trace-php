<?php

function get_php_info_as_string() {
    ob_start();
    phpinfo();
    $variable = ob_get_contents();
    ob_get_clean();
    return $variable;
}

function get_configuration_value($configuration) {
    $separator = "=>";
    $phpinfo = get_php_info_as_string();
    $start = strpos($phpinfo, $configuration);
    $start = strpos($phpinfo,$separator , $start);
    $end = strpos($phpinfo, "\n", $start);
    return trim(substr($phpinfo, $start + strlen($separator), $end - $start - strlen($separator)));
}