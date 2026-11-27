--TEST--
Downstream curl requests: handles pending in a multi handle (removed, re-added, freed, failed add)
--DESCRIPTION--
G4.17, G5.15, Q5.5: curl_multi_add_handle() only makes the handle pending;
W1 is made by the next curl_multi_exec() of that multi handle. A handle
removed before that exec starts no transfer and gets no call. Re-added, it
is started once, by the exec. A multi handle freed with a handle pending
drops the entry: the handle's own curl_exec() is the only transfer evaluated.
A failed add (the handle is in another multi handle: CURLM_ADDED_ALREADY)
leaves the entry of the handle's real multi handle alone and makes none for
the other one. Nothing is flushed twice.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
$lv = \datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'];
// libcurl 8.1.0 to 8.9.x crash (without the analysis too) when an easy
// handle of a multi freed without curl_multi_close() is used again
if ($lv >= 0x080100 && $lv < 0x080a00) die('skip libcurl 8.1.0-8.9.x crashes there');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 30, 'ok'));
rinit();

// the WAF calls made since the last call of this function
function calls($label)
{
    global $helper;
    ob_start(); // get_commands() prints "timeout" when there is nothing
    $execs = ce_execs($helper);
    ob_end_clean();
    $out = [];
    foreach ($execs as list($id, $last, $data)) {
        if ($last) {
            $out[] = isset($data['server.io.net.response.status'])
                ? "response {$data['server.io.net.response.status']}" : 'close';
        } else {
            $out[] = 'W1 ' . substr($data['server.io.net.url'], strpos($data['server.io.net.url'], 'PORT') + 4);
        }
    }
    echo "$label: ", $out ? implode(', ', $out) : '(no call)', "\n";
}

function run($mh)
{
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status == CURLM_OK);
}

function handle($url)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    return $ch;
}

echo "-- removed before the first exec\n";
$mh = curl_multi_init();
$a = handle("$base/json");
curl_multi_add_handle($mh, $a);
calls('after the add');
var_dump(curl_multi_remove_handle($mh, $a));
run($mh);
calls('after the exec');

echo "-- removed, then added again\n";
curl_multi_add_handle($mh, $a);
curl_multi_remove_handle($mh, $a);
curl_multi_add_handle($mh, $a);
curl_setopt($a, CURLOPT_URL, "$base/xml");
run($mh);
calls('after the exec');
var_dump(curl_multi_getcontent($a));
curl_multi_remove_handle($mh, $a);
calls('after the remove');

echo "-- multi handle freed with a handle pending\n";
$mh2 = curl_multi_init();
$b = handle("$base/json");
curl_multi_add_handle($mh2, $b);
unset($mh2);
calls('after the free');
var_dump(strlen(curl_exec($b)));
calls('after curl_exec');

echo "-- failed add\n";
$m1 = curl_multi_init();
$m2 = curl_multi_init();
$c = handle("$base/json");
var_dump(curl_multi_add_handle($m1, $c));
var_dump(curl_multi_add_handle($m2, $c) === CURLM_ADDED_ALREADY);
run($m2);
calls('exec of the multi handle that refused it');
run($m1);
calls('exec of the multi handle holding it');
var_dump(curl_multi_getcontent($c));
curl_multi_remove_handle($m1, $c);
calls('after the remove');

var_dump(rshutdown());
calls('at shutdown');
?>
--EXPECT--
-- removed before the first exec
after the add: (no call)
int(0)
after the exec: (no call)
-- removed, then added again
after the exec: W1 /xml, response 200
string(37) "<doc><item attr="v">text</item></doc>"
after the remove: (no call)
-- multi handle freed with a handle pending
after the free: (no call)
int(22)
after curl_exec: W1 /json, response 200
-- failed add
int(0)
bool(true)
exec of the multi handle that refused it: (no call)
exec of the multi handle holding it: W1 /json, response 200
string(22) "{"status":"ok","n":42}"
after the remove: (no call)
bool(true)
at shutdown: (no call)
