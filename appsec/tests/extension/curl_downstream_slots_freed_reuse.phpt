--TEST--
Downstream curl requests: our slot functions do not find the transfer of a freed handle (PHP 7)
--DESCRIPTION--
G5.4: our write, header and read functions only use a transfer found by the
slots' data (the php_curl *). On PHP 7 the state of a curl resource freed with
its transfer in flight (multi and easy handle unset) is only dropped lazily,
so its transfer is still registered under the freed php_curl *. The next
php_curl allocated of that size takes the same address: here a copy of an
observed handle, made (right after the free) and run from that handle's
write callback (a nested
curl_exec() is not normalised, G4.3), so it carries our functions with its
own php_curl * as data. Its calls must not be attributed to the dead
transfer: its registration is dropped when it is found dead.
(The counts below assume the copy reuses the freed address, as the Zend
allocator does; without that reuse the dead registration stays at 2.)
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID >= 80000) die('skip PHP 7: on PHP 8 the state goes with the CurlHandle');
if (getenv('USE_ZEND_ALLOC') === '0') die('skip needs the Zend allocator (address reuse)');
$lv = \datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'];
curl_skipif_needs_prereq('the write/header slots (below 7.80 only the read slot is used, tests/curl_calibrate)');
// see curl_eval_multi_freed.phpt: libcurl 8.1.0 - 8.9.x
if ($lv >= 0x080100 && $lv < 0x080a00) die('skip libcurl 8.1.0-8.9.x crashes there');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{curl_downstream_dump,curl_downstream_slot_registered_count};
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();

$mh = curl_multi_init();
$a = curl_init("$base/slow");
curl_setopt($a, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $a);
$t = microtime(true);
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);
    $d = curl_downstream_dump($a);
    $head = !empty($d['response']) && $d['response']['head_complete'];
} while ($running && !$head && microtime(true) - $t < 5);
echo 'in flight: ', cd_yn($head && $running), "\n";
// allocated before the first one is freed: not at its address
$b = curl_init("$base/json");
echo 'registered: ', curl_downstream_slot_registered_count(), "\n";

$c = null;
curl_setopt($b, CURLOPT_WRITEFUNCTION, function ($h, $data) use (&$c) {
    if ($c === null) {
        // freed right before the copy, so that the copy's php_curl is the
        // next allocation of that size
        unset($GLOBALS['mh']);
        unset($GLOBALS['a']);
        $c = curl_copy_handle($h);
        echo 'registered before the copy runs: ', curl_downstream_slot_registered_count(), "\n";
        curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
        echo 'copy: ', curl_exec($c), "\n";
        echo 'registered after the copy ran: ', curl_downstream_slot_registered_count(), "\n";
    }
    echo "app got: $data\n";
    return strlen($data);
});
var_dump(curl_exec($b));
$d = curl_downstream_dump($b);
echo 'observed=', cd_yn($d['observed']), ' write bytes=', $d['slot_bytes']['write'], "\n";
echo 'registered at the end: ', curl_downstream_slot_registered_count(), "\n";
?>
--EXPECT--
in flight: yes
registered: 1
registered before the copy runs: 2
copy: {"status":"ok","n":42}
registered after the copy ran: 1
app got: {"status":"ok","n":42}
bool(true)
observed=yes write bytes=22
registered at the end: 0
