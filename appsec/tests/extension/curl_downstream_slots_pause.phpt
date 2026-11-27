--TEST--
Downstream curl requests: a write call the app pauses is not counted twice
--DESCRIPTION--
G5.3: our write function returns what ext/curl's returns. When the app's
WRITEFUNCTION returns CURL_WRITEFUNC_PAUSE, libcurl keeps the data and
delivers it again once the transfer is unpaused: only the call whose data was
taken counts, so the bytes passed on equal the body length (no data appended
twice once the collector takes these calls over).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the write/header slots (below 7.80 only the read slot is used, tests/curl_calibrate)');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\curl_downstream_dump;
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();

$pause = defined('CURL_WRITEFUNC_PAUSE') ? CURL_WRITEFUNC_PAUSE : 0x10000001;
$ch = curl_init("$base/json");
$paused = 0;
$got = '';
curl_setopt_array($ch, [
    CURLOPT_WRITEFUNCTION => function ($h, $data) use (&$paused, &$got, $pause) {
        if ($paused === 0) {
            $paused = 1;
            return $pause;
        }
        $got .= $data;
        return strlen($data);
    },
    // fails rather than hangs if the transfer is never unpaused
    CURLOPT_TIMEOUT => 10,
    CURLOPT_NOPROGRESS => false,
    // not XFERINFOFUNCTION: PHP 7.0 and 7.1 do not have it
    CURLOPT_PROGRESSFUNCTION => function ($h) use (&$paused) {
        if ($paused === 1) {
            $paused = 2;
            curl_pause($h, CURLPAUSE_CONT);
        }
        return 0;
    },
]);
var_dump(curl_exec($ch));
echo "paused and resumed: ", $paused === 2 ? 'yes' : 'no', "\n";
echo "app got: $got\n";
$d = curl_downstream_dump($ch);
echo 'observed=', cd_yn($d['observed']), ' write bytes=', $d['slot_bytes']['write'],
    ' body length=', strlen($got), "\n";
?>
--EXPECT--
bool(true)
paused and resumed: yes
app got: {"status":"ok","n":42}
observed=yes write bytes=22 body length=22
