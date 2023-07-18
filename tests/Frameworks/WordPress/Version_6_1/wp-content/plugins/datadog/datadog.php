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
    // Retrieve the name of the endpoint (nginx + FASTcgi)
    // if it is 'simple', then echo "Simple text endpoint\n" and exit
    // Else, if it is 'error', then throw an exception
    // Else, do nothing
    $endpoint = $_SERVER['REQUEST_URI'];
    if (strpos($endpoint, 'simple') !== false) {
        echo "Simple text endpoint\n";
        exit;
    } else if (strpos($endpoint, 'error') !== false) {
        throw new Exception('Oops!');
    }
}

add_action('parse_request', 'datadog_parse_request');
