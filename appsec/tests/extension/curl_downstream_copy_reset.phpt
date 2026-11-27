--TEST--
Downstream curl requests: curl_copy_handle() and curl_reset()
--DESCRIPTION--
A copy gets the tracked options of its source but not its transfer. A copy
made from inside a callback of an observed transfer does not keep our slot
interposition (libcurl copies it); it is observed on its own transfer.
curl_reset() clears the tracked options, so a handle skipped because of
an object CURLOPT_CUSTOMREQUEST (unknown_option) is observed again.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response the collector holds');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{curl_downstream_installed_count,curl_downstream_tracking};
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();

$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-A: 1']);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_exec($ch);
$copy = curl_copy_handle($ch);
echo "copy before its transfer:\n";
cd_dump($copy);
cd_options($copy);
curl_setopt($copy, CURLOPT_HEADER, false);
curl_setopt($copy, CURLOPT_URL, "$base/echo");
curl_setopt($copy, CURLOPT_POSTFIELDS, 'copy body');
var_dump(curl_exec($copy));
cd_dump($copy, 'copy');
cd_dump($ch, 'source, unchanged');

// copy during a transfer
$ch = curl_init("$base/big?n=100000");
$copies = [];
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($h, $data) use (&$copies) {
    if (!$copies) {
        $copies[] = curl_copy_handle($h);
        echo 'installed during transfer: ', curl_downstream_installed_count(), "\n";
    }
    return strlen($data);
});
var_dump(curl_exec($ch));
cd_dump($ch, 'source of the copy made during the transfer');
$copy = $copies[0];
curl_setopt($copy, CURLOPT_WRITEFUNCTION, null);
curl_setopt($copy, CURLOPT_RETURNTRANSFER, true);
curl_setopt($copy, CURLOPT_URL, "$base/json");
var_dump(curl_exec($copy));
cd_dump($copy, 'copy made during the transfer');

// the copy of a skipped source is skipped
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, new class { function __toString() { return 'GET'; } });
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$copy = curl_copy_handle($ch);
var_dump(curl_exec($copy));
cd_dump($copy, 'copy of a skipped source');

// the copy of a handle without state: it has none either
curl_downstream_tracking(false);
$ch = curl_init("$base/json");
curl_downstream_tracking(true);
cd_dump($ch, 'source without state');
$copy = curl_copy_handle($ch);
curl_setopt($copy, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($copy));
cd_dump($copy, 'copy of a source without state');

// curl_reset
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, new class { function __toString() { return 'GET'; } });
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
cd_dump($ch, 'unknown option');
curl_reset($ch);
cd_options($ch);
curl_setopt($ch, CURLOPT_URL, "$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));
cd_dump($ch, 'after curl_reset');
?>
--EXPECT--
copy before its transfer:
observed=no reason=NULL installed=no hops=0
  url: 'http://127.0.0.1:PORT/json'
  default_protocol: NULL
  method: 'GET'
  customrequest: NULL
  httpheader: [0=>'X-A: 1']
  cookie: NULL
  postfields: NULL
  header: true
  readfunction: false
  infile: false
  prereqfunction: false
  followlocation: false
string(9) "copy body"
== copy
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=9, content-type=text/plain]
  resp body: 'copy body' complete=yes truncated=no
== source, unchanged
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
installed during transfer: 1
bool(true)
== source of the copy made during the transfer
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=100000, content-type=text/plain]
  resp body: 100000 bytes md5=13572e9e296cff52b79c52148313c3a5 complete=yes truncated=no
string(22) "{"status":"ok","n":42}"
== copy made during the transfer
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
string(22) "{"status":"ok","n":42}"
== copy of a skipped source
observed=no reason='unknown_option' installed=no hops=0
== source without state
not tracked
string(22) "{"status":"ok","n":42}"
== copy of a source without state
not tracked
== unknown option
observed=no reason='unknown_option' installed=no hops=0
  url: NULL
  default_protocol: NULL
  method: 'GET'
  customrequest: NULL
  httpheader: NULL
  cookie: NULL
  postfields: NULL
  header: false
  readfunction: false
  infile: false
  prereqfunction: false
  followlocation: false
string(22) "{"status":"ok","n":42}"
== after curl_reset
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
