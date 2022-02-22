<?php

use DDTrace\GlobalTracer;
use RandomizedTests\RandomExecutionPath;
use RandomizedTests\RandomExecutionPathConfiguration;
use RandomizedTests\SnippetsConfiguration;

$composerVendor = getenv('COMPOSER_VENDOR_DIR') ?: __DIR__ . '/../vendor';
require "$composerVendor/autoload.php";

/* Accepted query string parameters:
 *   - seed=123             reproducible execution path
 *   - execution_path=true  prints a graph that represents the nested calls
 */
$queries = array();
if (isset($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $queries);
}

$snippetsConfiguration = (new SnippetsConfiguration())
    ->withHttpBinHost('httpbin')
    ->withElasticSearchHost('elasticsearch')
    ->withMysqlHost('mysql')
    ->withMysqlUser('test')
    ->withMysqlPassword('test')
    ->withMysqlDb('test')
    ->withRedisHost('redis')
    ->withMemcachedHost('memcached');
$randomizerConfiguration = new RandomExecutionPathConfiguration(
    $snippetsConfiguration,
    isset($queries['seed']) ? intval($queries['seed']) : null,
    true,
    isset($queries['execution_path'])
);

$randomizer = new RandomExecutionPath($randomizerConfiguration);
set_error_handler([$randomizer, 'handleError']);
set_exception_handler([$randomizer, 'handleException']);
$output = $randomizer->randomPath();

// Legacy style manual tracing
$tracer = GlobalTracer::get();
$scope = $tracer->startActiveSpan('my_dummy_operation');
$scope->close();
