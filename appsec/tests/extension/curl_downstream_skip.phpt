--TEST--
Downstream curl requests: handles using libcurl's debug facilities are observed
--DESCRIPTION--
G5.5: a handle with CURLOPT_VERBOSE or CURLINFO_HEADER_OUT (as an option) is
observed like any other: the layer installs no debug callback and sets no
VERBOSE, so the verbose output still reaches CURLOPT_STDERR and
curl_getinfo(CURLINFO_HEADER_OUT) still returns the request head, and the
options stay as the app set them.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response the collector holds');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\rinit;
require __DIR__ . '/inc/curl_eval.php';
// the dumps show the bodies too
datadog\appsec\testing\curl_downstream_collect_all(true);
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 20, 'ok'), false);
var_dump(rinit());

$ch = curl_init("$base/json");
$err = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_VERBOSE, 1);
curl_setopt($ch, CURLOPT_STDERR, $err);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
cd_dump($ch, 'VERBOSE');
rewind($err);
$out = stream_get_contents($err);
var_dump(strpos($out, '> GET /json HTTP/1.1') !== false, strpos($out, '< HTTP/1.1 200 OK') !== false);

$ch = curl_init("$base/json");
curl_setopt_array($ch, [CURLINFO_HEADER_OUT => true, CURLOPT_RETURNTRANSFER => true]);
var_dump(curl_exec($ch));
cd_dump($ch, 'CURLINFO_HEADER_OUT');
$h = curl_getinfo($ch, CURLINFO_HEADER_OUT);
var_dump(strpos($h, "GET /json HTTP/1.1\r\n") === 0);
curl_setopt($ch, CURLINFO_HEADER_OUT, false);
var_dump(curl_exec($ch));
cd_dump($ch, 'CURLINFO_HEADER_OUT turned off');

// observed, then verbose: the verbose output is not swallowed
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
cd_dump($ch, 'observed first');
$err = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_VERBOSE, true);
curl_setopt($ch, CURLOPT_STDERR, $err);
curl_exec($ch);
rewind($err);
$out = stream_get_contents($err);
var_dump(strpos($out, '> GET /json HTTP/1.1') !== false);
cd_dump($ch, 'then VERBOSE');
// 5 transfers: a pre-send and a response call each
var_dump(count(ce_execs($helper)));

?>
--EXPECT--
bool(true)
string(22) "{"status":"ok","n":42}"
== VERBOSE
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
bool(true)
bool(true)
string(22) "{"status":"ok","n":42}"
== CURLINFO_HEADER_OUT
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
bool(true)
string(22) "{"status":"ok","n":42}"
== CURLINFO_HEADER_OUT turned off
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
== observed first
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
bool(true)
== then VERBOSE
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
int(10)
