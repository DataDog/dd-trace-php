--TEST--
A CURL * to readable memory without libcurl's magic number skips only that transfer
--DESCRIPTION--
There is no process-wide disable: the handle that fails validation is not
observed, and the next valid handle is validated on its own and observed.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (\datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] < 0x074b00) {
    die('skip libcurl < 7.75.0: the magic number is not checked');
}
?>
--FILE--
<?php
use function datadog\appsec\testing\{curl_handle_info,curl_handle_inject,curl_string_buffer_address};

$ch = curl_init();
echo curl_handle_info($ch)['status'], "\n";
$garbage = str_repeat('A', 16);
$i = curl_handle_inject(curl_string_buffer_address($garbage));
echo $i['status'], ': ', $i['reason'], "\n";
echo curl_handle_info($ch)['status'], "\n";
echo curl_handle_info(curl_init())['status'], "\n";
?>
--EXPECTF--
ok
invalid: CURL handle pointer 0x%x does not point to a libcurl easy handle (magic 0x41414141, expected 0xc0dedbad)
ok
ok
