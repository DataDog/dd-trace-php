--TEST--
Extract client IP address (no ip header set)
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
test('x_forwarded_for', '2001::1');
test('x_forwarded_for', '::1, febf::1, fc00::1, fd00::1,2001:0000::1');
test('x_forwarded_for', '172.16.0.1');
test('x_forwarded_for', '172.16.0.1, 172.31.255.254, 172.32.255.1, 8.8.8.8');
test('x_forwarded_for', '169.254.0.1, 127.1.1.1, 10.255.255.254,');
test('x_forwarded_for', '127.1.1.1,, ');
test('x_forwarded_for', 'bad_value, 1.1.1.1');

test('x_real_ip', '2.2.2.2');
test('x_real_ip', '2.2.2.2, 3.3.3.3');
test('x_real_ip', '127.0.0.1');
test('x_real_ip', '::ffff:4.4.4.4');
test('x_real_ip', '::ffff:127.0.0.1');
test('x_real_ip', 42);

test('client_ip', '2.2.2.2');

test('x_forwarded', 'for="[2001::1]:1111"');
test('x_forwarded', 'fOr="[2001::1]:1111"');
test('x_forwarded', 'for=some_host');
test('x_forwarded', 'for=127.0.0.1, FOR=1.1.1.1');
test('x_forwarded', 'for="\"foobar";proto=http,FOR="1.1.1.1"');
test('x_forwarded', 'for="8.8.8.8:2222",');
test('x_forwarded', 'for="8.8.8.8'); // quote not closed
test('x_forwarded', 'far="8.8.8.8",for=4.4.4.4;');
//\datadog\appsec\testing\stop_for_debugger();
test('x_forwarded', '   for=127.0.0.1,for= for=,for=;"for = for="" ,; for=8.8.8.8;');

test('x_cluster_client_ip', '2.2.2.2');

test('forwarded_for', '::1, 127.0.0.1, 2001::1');

test('forwarded', 'for=8.8.8.8');

test('via', '1.0 127.0.0.1, HTTP/1.1 [2001::1]:8888');
test('via', 'HTTP/1.1 [2001::1, HTTP/1.1 [2001::2]');
test('via', '8.8.8.8');
test('via', '8.8.8.8, 1.0 9.9.9.9:8888,');
test('via', '1.0 bad_ip_address, 1.0 9.9.9.9:8888,');
test('via', ",,8.8.8.8  127.0.0.1 6.6.6.6, 1.0\t  1.1.1.1\tcomment,");

test('true_client_ip', '8.8.8.8');

echo "remote address fallback: 8.8.8.8\n";
var_dump(extract_ip_addr(['REMOTE_ADDR' => '8.8.8.8']));

?>
--EXPECTF--
x_forwarded_for: 2001::1
string(7) "2001::1"

x_forwarded_for: ::1, febf::1, fc00::1, fd00::1,2001:0000::1
string(7) "2001::1"

x_forwarded_for: 172.16.0.1
NULL

x_forwarded_for: 172.16.0.1, 172.31.255.254, 172.32.255.1, 8.8.8.8
string(12) "172.32.255.1"

x_forwarded_for: 169.254.0.1, 127.1.1.1, 10.255.255.254,
NULL

x_forwarded_for: 127.1.1.1,, 
NULL

x_forwarded_for: bad_value, 1.1.1.1

Notice: datadog\appsec\testing\extract_ip_addr(): [ddappsec] Not recognized as IP address: "bad_value" in %s on line %d
string(7) "1.1.1.1"

x_real_ip: 2.2.2.2
string(7) "2.2.2.2"

x_real_ip: 2.2.2.2, 3.3.3.3

Notice: datadog\appsec\testing\extract_ip_addr(): [ddappsec] Not recognized as IP address: "2.2.2.2, 3.3.3.3" in %s on line %d
NULL

x_real_ip: 127.0.0.1
NULL

x_real_ip: ::ffff:4.4.4.4
string(7) "4.4.4.4"

x_real_ip: ::ffff:127.0.0.1
NULL

x_real_ip: 42
NULL

client_ip: 2.2.2.2
string(7) "2.2.2.2"

x_forwarded: for="[2001::1]:1111"
string(7) "2001::1"

x_forwarded: fOr="[2001::1]:1111"
string(7) "2001::1"

x_forwarded: for=some_host

Notice: datadog\appsec\testing\extract_ip_addr(): [ddappsec] Not recognized as IP address: "some_host" in %s on line %d
NULL

x_forwarded: for=127.0.0.1, FOR=1.1.1.1
string(7) "1.1.1.1"

x_forwarded: for="\"foobar";proto=http,FOR="1.1.1.1"

Notice: datadog\appsec\testing\extract_ip_addr(): [ddappsec] Not recognized as IP address: "\"foobar" in %s on line %d
string(7) "1.1.1.1"

x_forwarded: for="8.8.8.8:2222",
string(7) "8.8.8.8"

x_forwarded: for="8.8.8.8
NULL

x_forwarded: far="8.8.8.8",for=4.4.4.4;
string(7) "4.4.4.4"

x_forwarded:    for=127.0.0.1,for= for=,for=;"for = for="" ,; for=8.8.8.8;
string(7) "8.8.8.8"

x_cluster_client_ip: 2.2.2.2
string(7) "2.2.2.2"

forwarded_for: ::1, 127.0.0.1, 2001::1
string(7) "2001::1"

forwarded: for=8.8.8.8
string(7) "8.8.8.8"

via: 1.0 127.0.0.1, HTTP/1.1 [2001::1]:8888
string(7) "2001::1"

via: HTTP/1.1 [2001::1, HTTP/1.1 [2001::2]
string(7) "2001::2"

via: 8.8.8.8
NULL

via: 8.8.8.8, 1.0 9.9.9.9:8888,
string(7) "9.9.9.9"

via: 1.0 bad_ip_address, 1.0 9.9.9.9:8888,

Notice: datadog\appsec\testing\extract_ip_addr(): [ddappsec] Not recognized as IP address: "bad_ip_address" in %s on line %d
string(7) "9.9.9.9"

via: ,,8.8.8.8  127.0.0.1 6.6.6.6, 1.0	  1.1.1.1	comment,
string(7) "1.1.1.1"

true_client_ip: 8.8.8.8
string(7) "8.8.8.8"

remote address fallback: 8.8.8.8
string(7) "8.8.8.8"
