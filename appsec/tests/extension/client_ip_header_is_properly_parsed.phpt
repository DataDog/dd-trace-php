--TEST--
Client ip header is properly parsed
--FILE--
<?php
use function datadog\appsec\testing\extract_ip_addr;

function test($header, $value) {
    echo "$header: $value\n";
    $res = extract_ip_addr(['HTTP_' . strtoupper($header) => $value]);
    var_dump($res);
    echo "\n";
}

//Set value
ini_set("datadog.trace.client_ip_header", "some");
test('some', '8.8.8.8');

//Not found
ini_set("datadog.trace.client_ip_header", "another_value");
test('some', '8.8.8.8');

//it replaces hyphens for underscores
ini_set("datadog.trace.client_ip_header", "some-value");
test('some-value', '8.8.8.8');
test('some_value', '8.8.8.8');

//Empty sets
ini_set("datadog.trace.client_ip_header", "");
test('some', '8.8.8.8');

//Non strings
ini_set("datadog.trace.client_ip_header", 12345);
test('some', '8.8.8.8');
?>
--EXPECTF--
some: 8.8.8.8
string(7) "8.8.8.8"

some: 8.8.8.8
NULL

some-value: 8.8.8.8
NULL

some_value: 8.8.8.8
string(7) "8.8.8.8"

some: 8.8.8.8
NULL

some: 8.8.8.8
NULL