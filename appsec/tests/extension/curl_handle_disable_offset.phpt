--TEST--
An implausible CurlHandle handlers offset skips only that transfer
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--FILE--
<?php
use function datadog\appsec\testing\{curl_handle_info,curl_handle_inject,curl_string_buffer_address};

$ch = curl_init();
echo curl_handle_info($ch)['status'], "\n";
// the pointer would be valid; the offset is not
$magic = "\xad\xdb\xde\xc0" . str_repeat("\0", 12);
echo curl_handle_inject(curl_string_buffer_address($magic), 8)['status'], "\n";
$i = curl_handle_inject(curl_string_buffer_address($magic), 12);
echo $i['status'], ': ', $i['reason'], "\n";
echo curl_handle_info($ch)['status'], "\n";
?>
--EXPECTF--
ok
ok
invalid: the CurlHandle object handlers' offset is not a multiple of the pointer size
ok
