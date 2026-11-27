--TEST--
Downstream curl requests: our functions are in the three slots only during an observed transfer
--DESCRIPTION--
G5.2, G5.3, G5.4: for the duration of an observed transfer the write, header
and read slots of the handle's Curl_easy hold our functions, whose data is
still the handle's own php_curl *; at its end ext/curl's functions are back.
Ours forward every call to ext/curl's (the app gets its data; they are
counted for the transfer they belong to). A transfer that is not observed
(here: not http, no request being analysed) is not interposed.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the write/header slots (below 7.80 only the read slot is used, tests/curl_calibrate)');
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{curl_downstream_slots,curl_downstream_dump};
require __DIR__ . '/inc/curl_downstream.php';
$base = cd_server_start();

function slots($ch)
{
    $s = curl_downstream_slots($ch);
    return $s === null ? 'null' :
        "write={$s['write']} header={$s['header']} read={$s['read']} data={$s['data']}";
}

$ch = curl_init("$base/echo");
$during = [];
$sent = false;
curl_setopt_array($ch, [
    CURLOPT_UPLOAD => true,
    CURLOPT_INFILESIZE => 5,
    CURLOPT_READFUNCTION => function ($h, $fd, $len) use (&$sent, &$during) {
        $during['read'] = slots($h);
        if ($sent) {
            return '';
        }
        $sent = true;
        return 'hello';
    },
    CURLOPT_HEADERFUNCTION => function ($h, $line) use (&$during) {
        $during['header'] = slots($h);
        return strlen($line);
    },
    CURLOPT_WRITEFUNCTION => function ($h, $data) use (&$during) {
        $during['write'] = slots($h);
        echo "app got: $data\n";
        return strlen($data);
    },
]);
var_dump(curl_exec($ch));
foreach (['read', 'header', 'write'] as $k) {
    echo "during the $k callback: ", isset($during[$k]) ? $during[$k] : 'not called', "\n";
}
echo "after: ", slots($ch), "\n";
$d = curl_downstream_dump($ch);
echo 'observed=', cd_yn($d['observed']), ' calls of ours: write=', $d['slot_calls']['write'] > 0 ? '>0' : 0,
    ' header=', $d['slot_calls']['header'] > 0 ? '>0' : 0,
    ' read=', $d['slot_calls']['read'] > 0 ? '>0' : 0,
    ' write bytes=', $d['slot_bytes']['write'], "\n";

echo "a new handle: ", slots(curl_init()), "\n";

// not observed: not interposed either
datadog\appsec\testing\curl_downstream_collect_all(false);
$ch = curl_init("file://" . __FILE__);
$seen = null;
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($h, $data) use (&$seen) {
    $seen = slots($h);
    return strlen($data);
});
var_dump(curl_exec($ch));
echo "file:// during the write callback: $seen\n";
$d = curl_downstream_dump($ch);
echo 'observed=', cd_yn($d['observed']), ' reason=', $d['reason'], "\n";

echo "not a curl handle: ", var_export(curl_downstream_slots(42), true), "\n";
?>
--EXPECT--
app got: hello
bool(true)
during the read callback: write=ours header=ours read=ours data=own
during the header callback: write=ours header=ours read=ours data=own
during the write callback: write=ours header=ours read=ours data=own
after: write=pristine header=pristine read=pristine data=own
observed=yes calls of ours: write=>0 header=>0 read=>0 write bytes=5
a new handle: write=pristine header=pristine read=pristine data=own
bool(true)
file:// during the write callback: write=pristine header=pristine read=pristine data=own
observed=no reason=scheme
not a curl handle: NULL
