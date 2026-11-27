--TEST--
Downstream curl requests: handles with a CURLOPT_DEBUGFUNCTION are observed
--DESCRIPTION--
PHP 8.4+ (G5.5): the app's debug callback still gets the data, and such
handles are observed like any other; the option is left as the app set it.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq("the response the collector holds");
if (!defined('CURLOPT_DEBUGFUNCTION')) die('skip PHP 8.4+');
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
$types = [];
curl_setopt($ch, CURLOPT_DEBUGFUNCTION, function ($h, $type, $data) use (&$types) {
    $types[$type] = true;
});
curl_setopt($ch, CURLOPT_VERBOSE, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
var_dump(isset($types[CURLINFO_HEADER_OUT]), isset($types[CURLINFO_HEADER_IN]));
cd_dump($ch, 'DEBUGFUNCTION + VERBOSE');

$ch = curl_init("$base/json");
$calls = 0;
curl_setopt($ch, CURLOPT_DEBUGFUNCTION, function () use (&$calls) { $calls++; });
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
// no VERBOSE was set by the layer: libcurl produced no debug data
var_dump($calls);
cd_dump($ch, 'DEBUGFUNCTION only');
curl_setopt($ch, CURLOPT_DEBUGFUNCTION, null);
curl_exec($ch);
cd_dump($ch, 'DEBUGFUNCTION reset to null');
var_dump($calls);
// 3 transfers: a pre-send and a response call each
var_dump(count(ce_execs($helper)));
?>
--EXPECT--
bool(true)
string(22) "{"status":"ok","n":42}"
bool(true)
bool(true)
== DEBUGFUNCTION + VERBOSE
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
int(0)
== DEBUGFUNCTION only
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
== DEBUGFUNCTION reset to null
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
int(0)
int(6)
