--TEST--
Downstream curl requests: curl_multi transfers are observed and restored
--DESCRIPTION--
Two handles in a multi handle. Our callback is installed when their transfers
start, at the first curl_multi_exec() after they are added (not before:
libcurl reads the options then), restored when curl_multi_info_read() reports
them done, or when curl_multi_exec() returns with nothing running (or, at the
latest, when they are removed or the multi handle is closed).
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

$mh = curl_multi_init();
$ch1 = curl_init("$base/json");
$ch2 = curl_init("$base/chunked");
foreach ([$ch1, $ch2] as $ch) {
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_multi_add_handle($mh, $ch);
}
echo 'installed after add: ', curl_downstream_installed_count(), "\n";
curl_multi_exec($mh, $running);
echo 'installed after the first exec: ', curl_downstream_installed_count(), "\n";
$done = 0;
do {
    $status = curl_multi_exec($mh, $running);
    while (($info = curl_multi_info_read($mh)) !== false) {
        $done++;
        $d = datadog\appsec\testing\curl_downstream_dump($info['handle']);
        echo 'done: installed=', cd_yn($d['installed']), "\n";
    }
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running && $status == CURLM_OK);
echo "done: $done\n";
echo 'installed after done: ', curl_downstream_installed_count(), "\n";
var_dump(curl_multi_getcontent($ch1), curl_multi_getcontent($ch2));
cd_dump($ch1, 'ch1');
cd_dump($ch2, 'ch2');
curl_multi_remove_handle($mh, $ch1);
curl_multi_remove_handle($mh, $ch2);

// without curl_multi_info_read(): restored by the curl_multi_exec() that
// finds nothing running (G4.18)
$ch3 = curl_init("$base/json");
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch3);
do {
    curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running);
echo 'installed before remove: ', curl_downstream_installed_count(), "\n";
curl_multi_remove_handle($mh, $ch3);
echo 'installed after remove: ', curl_downstream_installed_count(), "\n";
cd_dump($ch3, 'ch3');

// restored by curl_multi_close()
$ch4 = curl_init("$base/json");
curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch4);
curl_multi_exec($mh, $running);
echo 'installed before close: ', curl_downstream_installed_count(), "\n";
curl_multi_close($mh);
echo 'installed after close: ', curl_downstream_installed_count(), "\n";

// multi and handle dropped mid-transfer: the handle's CURL * is cleaned up
// with our callback installed; nothing may use it afterwards
$mh = curl_multi_init();
$ch5 = curl_init("$base/big?n=200000");
curl_setopt($ch5, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch5);
curl_multi_exec($mh, $running);
unset($mh, $ch5);
$ch6 = curl_init("$base/json");
curl_setopt($ch6, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch6));
cd_dump($ch6, 'ch6');
?>
--EXPECTF--
installed after add: 0
installed after the first exec: 2
done: installed=no
done: installed=no
done: 2
installed after done: 0
string(22) "{"status":"ok","n":42}"
string(15) "0123456789abcde"
== ch1
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
== ch2
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-type=text/plain, transfer-encoding=chunked]
  resp body: '0123456789abcde' complete=%s truncated=no
installed before remove: 0
installed after remove: 0
== ch3
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=no truncated=no
installed before close: 1
installed after close: 0
string(22) "{"status":"ok","n":42}"
== ch6
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
