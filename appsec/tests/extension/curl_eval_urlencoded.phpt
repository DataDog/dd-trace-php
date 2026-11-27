--TEST--
Downstream curl requests are evaluated natively: urlencoded and multipart bodies
--DESCRIPTION--
A string CURLOPT_POSTFIELDS without Content-Type is sent by libcurl as
application/x-www-form-urlencoded and parsed like parse_str(); an
urlencoded response is not parsed (G7.8: only JSON and XML responses are). An
array CURLOPT_POSTFIELDS (multipart) is sent as its fields; a CURLFile part is
dropped (not read, not parsed).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/form");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'a=1&b[]=2&b[]=3');
var_dump(curl_exec($ch));

$file = tempnam(sys_get_temp_dir(), 'cde');
file_put_contents($file, '{"in":"file"}');
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['k' => 'v', 'f' => new CURLFile($file, 'application/json', 'f.json')]);
var_dump(curl_exec($ch));
unlink($file);
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
string(19) "k=v&arr[]=1&arr[]=2"
string(22) "{"status":"ok","n":42}"
bool(true)
curl-1-0 {"server.io.net.request.body":{"a":"1","b":["2","3"]},"server.io.net.request.headers":{"content-type":["application/x-www-form-urlencoded"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/form"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["19"],"content-type":["application/x-www-form-urlencoded"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.body":{"k":"v"},"server.io.net.request.headers":{"content-type":["multipart/form-data"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-2-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
