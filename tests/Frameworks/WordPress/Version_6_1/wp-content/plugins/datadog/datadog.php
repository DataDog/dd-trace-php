<?php
/*
 * @wordpress-plugin
 * Plugin Name: Datadog Sample
 * Plugin URI: https://www.datadoghq.com/
 * Description: Just here for testing
 * Version: 0.0.0
 * Author: Datadog
 * Author URI: https://www.datadoghq.com/
 * License: GPLv2 or later
 * Text Domain: datadog-sample
*/

function datadog_parse_request($wp) {
    if (!isset($_SERVER['PATH_INFO'])) {
        return;
    }

    $pathInfo = $_SERVER['PATH_INFO'];
    if ('/simple' === $pathInfo) {
        echo "Simple text endpoint\n";
        exit;
    }
    if ('/error' === $pathInfo) {
        throw new Exception('Oops!');
    }
}

add_action('parse_request', 'datadog_parse_request');
