--TEST--
Closed curl handles: not_curl on PHP 7, still valid on PHP 8
--DESCRIPTION--
On PHP 7, curl_close() destroys the resource (type -1, ptr NULL): the lookup
reports "not a curl handle" and reads no freed memory. On PHP 8, curl_close()
is a no-op and the CurlHandle keeps its live CURL * until the object is
freed, so the handle is still valid.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--FILE--
<?php
use function datadog\appsec\testing\curl_handle_info;

$ch = curl_init();
echo curl_handle_info($ch)['status'], "\n";
@curl_close($ch);
$expected = PHP_VERSION_ID >= 80000 ? 'ok' : 'not_curl';
$status = curl_handle_info($ch)['status'];
echo $status === $expected ? "as expected\n" : "unexpected: $status\n";
?>
--EXPECT--
ok
as expected
