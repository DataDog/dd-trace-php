--TEST--
A transfer skipped by handle validation logs one debug line to the AppSec log, no warning
--DESCRIPTION--
Every failing transfer logs one debug skip line to the AppSec log (here a
file; with the default, php_error_reporting, it is not logged at all) and
nothing above debug level. Nothing reaches the application as a PHP warning:
no error handler call, nothing in error_get_last() or the output, even from
inside libcurl.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (\datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] < 0x074b00) {
    die('skip libcurl < 7.75.0: the magic number is not checked');
}
?>
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test_curl_disable.log
datadog.appsec.log_level=debug
--FILE--
<?php
use function datadog\appsec\testing\{curl_handle_inject,curl_string_buffer_address};
include __DIR__ . '/inc/logging.php';
truncate_log();
set_error_handler(function ($no, $str) {
    echo "error handler: $str\n";
    return true;
});
$garbage = str_repeat('A', 16);
echo curl_handle_inject(curl_string_buffer_address($garbage))['status'], "\n";
echo curl_handle_inject(curl_string_buffer_address($garbage))['status'], "\n";
// the other skips: an implausible handlers offset, unreadable memory
echo curl_handle_inject(curl_string_buffer_address($garbage), 12)['status'], "\n";
echo curl_handle_inject(0x8000)['status'], "\n";
var_dump(error_get_last());
$log = log_contents();
$re = '/\[debug\] Not observing a curl transfer: CURL handle pointer 0x[0-9a-f]+ does not point to a libcurl easy handle/';
echo preg_match_all($re, $log), " skip line(s)\n";
$re = "/\\[debug\\] Not observing a curl transfer: the CurlHandle object handlers' offset is not a multiple of the pointer size/";
echo preg_match_all($re, $log), " offset skip line(s)\n";
$re = '/\[debug\] Not observing a curl transfer: CURL handle pointer 0x8000 points to unreadable memory/';
echo preg_match_all($re, $log), " unreadable skip line(s)\n";
echo preg_match_all('/\[(warning|error)\]/', $log), " warning(s)\n";
?>
--EXPECT--
invalid
invalid
invalid
invalid
NULL
2 skip line(s)
1 offset skip line(s)
1 unreadable skip line(s)
0 warning(s)
