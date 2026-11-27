--TEST--
Downstream curl requests: clone used after the state of its source is freed
--DESCRIPTION--
A clone made while its source's transfer is observed must not keep our
CURLOPT_PREREQFUNCTION/PREREQDATA: PREREQDATA points to the source's state,
which is freed with the source. Run with USE_ZEND_ALLOC=0 so that ASAN
sees a use of it.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
if (PHP_VERSION_ID < 80000) die('skip PHP 8+: curl handles are objects');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
USE_ZEND_ALLOC=0
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 30, 'ok'));
var_dump(rinit());
$mh = curl_multi_init();
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_multi_add_handle($mh, $ch));
curl_multi_exec($mh, $running);
$c2 = clone $ch;
var_dump(curl_multi_remove_handle($mh, $ch));
unset($ch);
echo "source freed\n";
curl_setopt($c2, CURLOPT_URL, "$base/xml");
var_dump(curl_exec($c2));
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print(array_filter($execs, function ($e) { return $e[0] === 'curl-2-0'; }));
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
int(0)
int(0)
source freed
string(37) "<doc><item attr="v">text</item></doc>"
bool(true)
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/xml"}
curl-2-0 last {"server.io.net.response.body":{"doc":[{"item":[{"@attr":"v"},"text"]}]},"server.io.net.response.headers":{"connection":["close"],"content-length":["37"],"content-type":["application/xml"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
