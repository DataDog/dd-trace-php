--TEST--
Extract client IP address (ip env set)
--ENV--
DD_TRACE_CLIENT_IP_HEADER=foo-Bar
--INI--
datadog.appsec.log_level=info
--FILE--
<?php
use function datadog\appsec\testing\extract_ip_addr;

function test($header, $value) {
    echo "$header: $value\n";
    $res = extract_ip_addr(['HTTP_' . strtoupper($header) => $value]);
    var_dump($res);
    echo "\n";
}

test('foo_bar', '127.0.0.1, 8.8.8.8');
test('foo_bar', 'for=::1, for=8.8.8.8');
test('foo_bar', 'for=::1, for=[::ffff:1.1.1.1]:8888');
test('foo_bar', '10.0.0.1');

echo "unused remote address fallback: 8.8.8.8\n";
var_dump(extract_ip_addr(['REMOTE_ADDR' => '8.8.8.8']));

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
NULL
