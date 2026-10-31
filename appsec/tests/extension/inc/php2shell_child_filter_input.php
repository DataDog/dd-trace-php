<?php
// Child script for php2shell_mitigation_input_filter_ext_filter.phpt. Prints,
// for the request's GET or POST data, the raw value ext/filter stored
// (filter_input()) and the value in the superglobal (after filter.default).
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = INPUT_GET;
    $arr = $_GET;
} else {
    $type = INPUT_POST;
    $arr = $_POST;
}
echo "SCRIPT RAN ", $_SERVER['REQUEST_METHOD'],
    " raw=", var_export(filter_input($type, 'a'), true),
    " filtered=", var_export(isset($arr['a']) ? $arr['a'] : null, true),
    " c=", json_encode(isset($arr['c']) ? $arr['c'] : null),
    "\n";
