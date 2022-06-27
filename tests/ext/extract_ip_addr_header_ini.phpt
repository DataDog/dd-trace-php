--TEST--
Extract client IP address (ip header set)
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: http.client_ip only available on php 7 and 8'); ?>
--INI--
datadog.trace.client_ip_header=foo-Bar
--FILE--
<?php
use function DDTrace\Testing\extract_ip_from_headers;

function test($header, $value) {
    echo "$header: $value\n";
    $res = extract_ip_from_headers(['HTTP_' . strtoupper($header) => $value]);
    if (array_key_exists('http.client_ip', $res)) {
        var_dump($res['http.client_ip']);
    } else {
        echo "NULL\n";
    }
    echo "\n";
}

test('foo_bar', '127.0.0.1, 8.8.8.8');
test('foo_bar', 'for=::1, for=8.8.8.8');
test('foo_bar', 'for=::1, for=[::ffff:1.1.1.1]:8888');
test('foo_bar', '10.0.0.1');

echo "unused remote address fallback: 8.8.8.8\n";
var_dump(extract_ip_from_headers(['REMOTE_ADDR' => '8.8.8.8']));

?>
--EXPECTF--
foo_bar: 127.0.0.1, 8.8.8.8
string(7) "8.8.8.8"

foo_bar: for=::1, for=8.8.8.8
string(7) "8.8.8.8"

foo_bar: for=::1, for=[::ffff:1.1.1.1]:8888
string(7) "1.1.1.1"

foo_bar: 10.0.0.1
NULL

unused remote address fallback: 8.8.8.8
array(0) {
}
