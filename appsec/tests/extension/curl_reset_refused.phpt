--TEST--
Downstream curl requests: curl_reset() refused from a callback
--DESCRIPTION--
G4.15: the recorded options are cleared only if the reset took effect. PHP 7.0
and 7.4 refuse a curl_reset() from inside a callback of the handle's transfer
with a warning, returning NULL and resetting nothing (the handle keeps
POSTFIELDS and COOKIE); on PHP 8 a refused reset throws. Either way the
recorded options stay, and a reset outside a callback clears them.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();

$ch = curl_init("$base/echo");
$refused = null;
curl_setopt_array($ch, [
    CURLOPT_COOKIE => 'c=1',
    CURLOPT_POSTFIELDS => 'body',
    CURLOPT_WRITEFUNCTION => function ($h, $data) use (&$refused) {
        if ($refused === null) {
            set_error_handler(function () { return true; });
            try {
                curl_reset($h);
                $refused = false;
            } catch (\Throwable $e) {
                $refused = true;
            }
            restore_error_handler();
        }
        return strlen($data);
    },
]);
var_dump(curl_exec($ch));
// PHP 7: a warning and NULL (nothing reset); PHP 8: an Error
var_dump($refused !== null);
echo 'handle kept its URL: ',
    cd_yn(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) === "$base/echo"), "\n";
echo "-- after the refused reset\n";
cd_options($ch);

curl_reset($ch);
echo "-- after a reset that took effect\n";
cd_options($ch);
?>
--EXPECT--
bool(true)
bool(true)
handle kept its URL: yes
-- after the refused reset
  url: 'http://127.0.0.1:PORT/echo'
  default_protocol: NULL
  method: 'POST'
  customrequest: NULL
  httpheader: NULL
  cookie: 'c=1'
  postfields: 'body'
  header: false
  readfunction: false
  infile: false
  prereqfunction: false
  followlocation: false
-- after a reset that took effect
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
