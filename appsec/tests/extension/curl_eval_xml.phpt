--TEST--
Downstream curl requests are evaluated natively: XML bodies
--DESCRIPTION--
Request and response bodies with an XML content type are parsed like
convert_xml() does.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/xml");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml; charset=utf-8']);
curl_setopt($ch, CURLOPT_POSTFIELDS, '<req><v>1</v></req>');
var_dump(curl_exec($ch));
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
string(37) "<doc><item attr="v">text</item></doc>"
bool(true)
curl-1-0 {"server.io.net.request.body":{"req":[{"v":["1"]}]},"server.io.net.request.headers":{"content-type":["text/xml; charset=utf-8"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/xml"}
curl-1-0 last {"server.io.net.response.body":{"doc":[{"item":[{"@attr":"v"},"text"]}]},"server.io.net.response.headers":{"connection":["close"],"content-length":["37"],"content-type":["application/xml"]},"server.io.net.response.status":"200"}
1 subcontext(s), all closed
