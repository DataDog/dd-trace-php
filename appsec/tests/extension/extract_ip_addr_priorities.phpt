--TEST--
Extract client IP address (priorites)
--INI--
datadog.appsec.log_level=info
--FILE--
<?php
use function datadog\appsec\testing\extract_ip_addr;

$all = [
    'fastly_client_ip' => '8.8.8.8',
    'x_real_ip' => '2.2.2.2',
    'cf_connecting_ipv6' => '2001::1',
    'x_forwarded_for' => '1.1.1.1',
    'true_client_ip' => '3.3.3.3',
    'cf_connecting_ip' => '9.9.9.9',
    'x_client_ip' => '4.4.4.4',
    'x_cluster_client_ip' => '7.7.7.7',
    'x_forwarded' => 'for="5.5.5.5"',
    'forwarded_for' => '6.6.6.6',
];
$all = array_combine(array_map(
    function ($key) { return 'HTTP_' . strtoupper($key); }, array_keys($all)),
    array_values($all));

function test($header) {
    global $all;
    if (!empty($header)) {
        echo "After removing $header:\n";
        $header = 'HTTP_' . strtoupper($header);
        unset($all[$header]);
    } else {
        echo "Without removing anything:\n";
    }
    $res = extract_ip_addr($all);
    var_dump($res);
    echo "\n";
}
test('');
test('x_forwarded_for');
test('x_real_ip');
test('true_client_ip');
test('x_client_ip');
test('x_forwarded');
test('forwarded_for');
test('x_cluster_client_ip');
test('fastly_client_ip');
test('cf_connecting_ip');
test('cf_connecting_ipv6');

?>
--EXPECTF--
Without removing anything:
string(7) "1.1.1.1"

After removing x_forwarded_for:
string(7) "2.2.2.2"

After removing x_real_ip:
string(7) "3.3.3.3"

After removing true_client_ip:
string(7) "4.4.4.4"

After removing x_client_ip:
string(7) "5.5.5.5"

After removing x_forwarded:
string(7) "6.6.6.6"

After removing forwarded_for:
string(7) "7.7.7.7"

After removing x_cluster_client_ip:
string(7) "8.8.8.8"

After removing fastly_client_ip:
string(7) "9.9.9.9"

After removing cf_connecting_ip:
string(7) "2001::1"

After removing cf_connecting_ipv6:
NULL
