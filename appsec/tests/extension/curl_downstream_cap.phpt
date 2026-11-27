--TEST--
Downstream curl requests: DD_APPSEC_MAX_BODY_BUFF_SIZE caps collected bodies
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response the collector holds');
?>
--INI--
datadog.appsec.enabled=1
datadog.appsec.max_body_buff_size=16
--FILE--
<?php
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();
$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'abcdefghijklmnopqrstuvwxyz');
var_dump(curl_exec($ch));
cd_dump($ch, 'over the cap');

$ch = curl_init("$base/chunked");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
cd_dump($ch, 'chunked, over the cap');

$ch = curl_init("$base/big?n=16");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
cd_dump($ch, 'exactly the cap');
?>
--EXPECTF--
string(26) "abcdefghijklmnopqrstuvwxyz"
== over the cap
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=26, content-type=text/plain]
  resp body: 'abcdefghijklmnop' complete=yes truncated=yes
string(15) "0123456789abcde"
== chunked, over the cap
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-type=text/plain, transfer-encoding=chunked]
  resp body: '0123456789abcde' complete=yes truncated=no
string(16) "0123456789012345"
== exactly the cap
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=16, content-type=text/plain]
  resp body: '0123456789012345' complete=yes truncated=no
