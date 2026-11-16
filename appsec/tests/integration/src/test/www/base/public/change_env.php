<?php

$root_span = \DDTrace\root_span();
if (key_exists('ini', $_GET)) {
    ini_set('datadog.env', $_GET['env']);
} else {
    $root_span->env = $_GET['env'];
}

var_dump($root_span);
