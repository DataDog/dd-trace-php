--TEST--
[profiling] test profiler's service when none is given (web)
--DESCRIPTION--
When DD_SERVICE isn't provided, default to web.request.
This behavior matches the tracer's and should be kept in sync.
--SKIPIF--
--ENV--
DD_PROFILING_ENABLED=no
DD_SERVICE=
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo?some=query&parameters
QUERY_STRING=some=query&parameters
METHOD=GET
--GET--
some=query&parameters
--INI--
assert.exception=1
--FILE--
<?php

// Test has been screwed up, be sure to run with a web SAPI.
assert(php_sapi_name() !== 'cli');

// Can't be in the SKIPIF for CGI requests for some reason
assert(extension_loaded('datadog-profiling'));
assert(extension_loaded('dom'));

ob_start();
$extension = new ReflectionExtension('datadog-profiling');
$extension->info();
$output = ob_get_clean();

$values = [];

/* Expect two tables.
 *  1. Two column layout, first column is the key and second is the value.
 *  2. Three column layout for .ini settings. First is the directive, second
 *     is the local value, and last is the master value.
 * For this test, we're after the first table for DD_SERVICE.
 */
$dom = new DOMDocument();
assert($dom->loadHTML($output));
$tables = iterator_to_array($dom->getElementsByTagName('table'));
assert(count($tables) === 2);

foreach ($tables[0]->getElementsByTagName('tr') as $row) {
    [$key, $value] = iterator_to_array($row->getElementsByTagName('td'));
    $key = html_entity_decode(trim($key->nodeValue));
    $value = html_entity_decode(trim($value->nodeValue));
    $values[$key] = $value;
}

$key = "Application's Service (DD_SERVICE)";
$value = 'web.request';
assert($values[$key] == $value, "Expected {$values[$key]} == {$value}");

echo "Done.";

?>
--EXPECT--
Done.
