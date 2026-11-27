--TEST--
Downstream curl requests are observed through the interposed libcurl functions
--DESCRIPTION--
curl_exec() GET and POST (JSON) requests against a local server: the response
(status, headers, body) is collected from the interposed header and write
functions; afterwards they are no longer installed. The recorded options
(G4.6) are tracked from curl_init()/curl_setopt()/curl_setopt_array().
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response the collector holds');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\curl_downstream_installed_count;
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();
var_dump(datadog\appsec\is_enabled());

$ch = curl_init("$base/json?a=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'X-Multi: 1', 'x-multi: 2']);
$res = curl_exec($ch);
var_dump($res);
cd_dump($ch, 'GET');
cd_options($ch);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "$base/echo",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS => '{"user":"x","id":1}',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_COOKIE => 'a=b',
]);
$res = curl_exec($ch);
var_dump($res);
cd_dump($ch, 'POST');
cd_options($ch);
var_dump(curl_downstream_installed_count());
?>
--EXPECT--
bool(true)
string(22) "{"status":"ok","n":42}"
== GET
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
  url: 'http://127.0.0.1:PORT/json?a=1'
  default_protocol: NULL
  method: 'GET'
  customrequest: NULL
  httpheader: [0=>'Accept: application/json', 1=>'X-Multi: 1', 2=>'x-multi: 2']
  cookie: NULL
  postfields: NULL
  header: false
  readfunction: false
  infile: false
  prereqfunction: false
  followlocation: false
string(19) "{"user":"x","id":1}"
== POST
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=19, content-type=text/plain]
  resp body: '{"user":"x","id":1}' complete=yes truncated=no
  url: 'http://127.0.0.1:PORT/echo'
  default_protocol: NULL
  method: 'POST'
  customrequest: NULL
  httpheader: [0=>'Content-Type: application/json']
  cookie: 'a=b'
  postfields: '{"user":"x","id":1}'
  header: false
  readfunction: false
  infile: false
  prereqfunction: false
  followlocation: false
int(0)
