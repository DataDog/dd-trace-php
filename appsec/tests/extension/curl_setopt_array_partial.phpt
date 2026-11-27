--TEST--
Downstream curl requests: curl_setopt_array() failing midway
--DESCRIPTION--
G4.12: for a tracked handle, curl_setopt_array() applies its elements in order
as curl_setopt() calls and stops at the first one that fails: what is recorded
is exactly what was applied (the elements after the failing one are not), and
the return value is ext/curl's. A string key fails at its position, like in
ext/curl.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response the collector holds');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{curl_downstream_dump,curl_downstream_tracking};
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();

function array_call($ch, $opts) {
    set_error_handler(function () { return true; });
    try {
        $r = curl_setopt_array($ch, $opts);
        echo $r === true ? "applied all\n" : "failed\n";
    } catch (\Throwable $e) {
        echo "failed\n"; // PHP 8: an exception
    }
    restore_error_handler();
}

echo "-- libcurl rejects the third element\n";
$ch = curl_init();
array_call($ch, [
    CURLOPT_URL => "$base/json",
    CURLOPT_HTTPHEADER => ['X-A: 1'],
    CURLOPT_TIMEOUT => -1,
    CURLOPT_COOKIE => 'c=1',
    CURLOPT_RETURNTRANSFER => true,
]);
cd_options($ch);

echo "-- string key after two elements\n";
$ch = curl_init();
array_call($ch, [
    CURLOPT_URL => "$base/json",
    CURLOPT_COOKIE => 'c=1',
    'not an option' => 1,
    CURLOPT_HTTPHEADER => ['X-A: 1'],
]);
cd_options($ch);

echo "-- all applied\n";
$ch = curl_init();
array_call($ch, [
    CURLOPT_URL => "$base/json",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
]);
cd_options($ch);
var_dump(strlen(curl_exec($ch)) > 22);
cd_dump($ch);

echo "-- the elements are curl_setopt() calls\n";
$ch = curl_init();
array_call($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIE => 'x=y']);
var_dump(curl_downstream_dump($ch)['options']['followlocation']);

echo "-- a handle without state is ext/curl's alone\n";
curl_downstream_tracking(false);
$ch = curl_init();
curl_downstream_tracking(true);
array_call($ch, [
    CURLOPT_URL => "$base/json",
    CURLOPT_HTTPHEADER => ['X-A: 1'],
    CURLOPT_TIMEOUT => -1,
    CURLOPT_COOKIE => 'c=1',
]);
cd_options($ch);
var_dump(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) !== null);
var_dump(curl_setopt($ch, CURLOPT_URL, "$base/json")
    && curl_setopt($ch, CURLOPT_RETURNTRANSFER, true));
var_dump(curl_exec($ch));
cd_dump($ch);
?>
--EXPECT--
-- libcurl rejects the third element
failed
  url: 'http://127.0.0.1:PORT/json'
  default_protocol: NULL
  method: 'GET'
  customrequest: NULL
  httpheader: [0=>'X-A: 1']
  cookie: NULL
  postfields: NULL
  header: false
  readfunction: false
  infile: false
  prereqfunction: false
  followlocation: false
-- string key after two elements
failed
  url: 'http://127.0.0.1:PORT/json'
  default_protocol: NULL
  method: 'GET'
  customrequest: NULL
  httpheader: NULL
  cookie: 'c=1'
  postfields: NULL
  header: false
  readfunction: false
  infile: false
  prereqfunction: false
  followlocation: false
-- all applied
applied all
  url: 'http://127.0.0.1:PORT/json'
  default_protocol: NULL
  method: 'GET'
  customrequest: NULL
  httpheader: NULL
  cookie: NULL
  postfields: NULL
  header: true
  readfunction: false
  infile: false
  prereqfunction: false
  followlocation: false
bool(true)
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
-- the elements are curl_setopt() calls
applied all
bool(true)
-- a handle without state is ext/curl's alone
failed
not tracked
bool(true)
bool(true)
string(22) "{"status":"ok","n":42}"
not tracked
