--TEST--
Ensure the ip header priority is followed
--FILE--
<?php
use function datadog\appsec\testing\extract_ip_addr;

//Php does not have a function to shuffle associative arrays :)
function shuffle_assoc($arr)
{
    $keys = array_keys($arr);
    shuffle($keys);
    foreach($keys as $key) {
        $new[$key] = $arr[$key];
    }
    $arr = $new;
    return $arr;
}

function test($headers, $key_expected) {
    $headers_formatted_as_php = [];
    foreach ($headers as $key => $value) {
        $headers_formatted_as_php['HTTP_' . strtoupper($key)] = $value;
    }
    //Lets shuffle so the order of the input is not relevant
    shuffle_assoc($headers_formatted_as_php);
    $res = DDTrace\extract_ip_from_headers($headers_formatted_as_php);
    echo "Testing '$key_expected': Result is: ".$res['http.client_ip'].PHP_EOL;
}

$headers = [
'x_forwarded_for' => '7.7.7.1',
'x_real_ip' => '7.7.7.2',
'forwarded' => 'for="7.7.7.10"',
'true_client_ip' => '7.7.7.3',
'x_client_ip' => '7.7.7.4',
'x_forwarded' => 'for="7.7.7.5"',
'forwarded_for' => '7.7.7.6',
'x_cluster_client_ip' => '7.7.7.7',
'fastly_client_ip' => '7.7.7.8',
'cf_connecting_ip' => '7.7.7.9',
'cf_connecting_ipv6' => '2001::1',
];

test($headers, 'x_forwarded_for');
unset($headers['x_forwarded_for']); //Lets remove it so it it not top priority any more
test($headers, 'x_real_ip');
unset($headers['x_real_ip']); //Lets remove it so it it not top priority any more
test($headers, 'forwarded');
unset($headers['forwarded']); //Lets remove it so it it not top priority any more
test($headers, 'true_client_ip');
unset($headers['true_client_ip']); //Lets remove it so it it not top priority any more
test($headers, 'x_client_ip');
unset($headers['x_client_ip']); //Lets remove it so it it not top priority any more
test($headers, 'x_forwarded');
unset($headers['x_forwarded']); //Lets remove it so it it not top priority any more
test($headers, 'forwarded_for');
unset($headers['forwarded_for']); //Lets remove it so it it not top priority any more
test($headers, 'x_cluster_client_ip');
unset($headers['x_cluster_client_ip']); //Lets remove it so it it not top priority any more
test($headers, 'fastly_client_ip');
unset($headers['fastly_client_ip']); //Lets remove it so it it not top priority any more
test($headers, 'cf_connecting_ip');
unset($headers['cf_connecting_ip']); //Lets remove it so it it not top priority any more
test($headers, 'cf_connecting_ipv6');
unset($headers['cf_connecting_ipv6']); //Lets remove it so it it not top priority any more

?>
--EXPECTF--
Testing 'x_forwarded_for': Result is: 7.7.7.1
Testing 'x_real_ip': Result is: 7.7.7.2
Testing 'forwarded': Result is: 7.7.7.10
Testing 'true_client_ip': Result is: 7.7.7.3
Testing 'x_client_ip': Result is: 7.7.7.4
Testing 'x_forwarded': Result is: 7.7.7.5
Testing 'forwarded_for': Result is: 7.7.7.6
Testing 'x_cluster_client_ip': Result is: 7.7.7.7
Testing 'fastly_client_ip': Result is: 7.7.7.8
Testing 'cf_connecting_ip': Result is: 7.7.7.9
Testing 'cf_connecting_ipv6': Result is: 2001::1
