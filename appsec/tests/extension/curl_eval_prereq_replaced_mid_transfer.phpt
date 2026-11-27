--TEST--
Downstream curl requests: an app's PREREQFUNCTION set during the transfer closes the current hop without data
--DESCRIPTION--
G5.12, G5.13: the hops come only from our own PREREQ calls. When the app sets
its own CURLOPT_PREREQFUNCTION while the transfer runs (here from its header
function, at the 302 of hop 0, a JSON one), the hops that follow start no
hop of ours: their head lines are ignored and their body bytes would be
appended next to hop 0's head. What the collector holds then no longer belongs to one hop, so
hop 0 is closed with the call without data instead of a response call that
mixes hop 0's head with hop 1's body. The same holds on a multi handle, where
the next curl_multi_exec ends the observation (skip reason prereqfunction).
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif_response.php';
if (!defined('CURLOPT_PREREQFUNCTION')) die('skip PHP 8.4+');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
var_dump(rinit());

$app_calls = 0;
function replace_prereq_at_302($ch, &$app_calls) {
    $set = false;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADERFUNCTION =>
            function ($ch, $line) use (&$set, &$app_calls) {
                if (!$set && stripos($line, 'location:') === 0) {
                    $set = true;
                    curl_setopt($ch, CURLOPT_PREREQFUNCTION,
                        function () use (&$app_calls) {
                            $app_calls++;
                            return CURL_PREREQFUNC_OK;
                        });
                }
                return strlen($line);
            },
    ]);
}

echo "== 1. curl_exec\n";
$ch = curl_init("$base/redirect-to?json=1");
replace_prereq_at_302($ch, $app_calls);
var_dump(curl_exec($ch));

echo "== 2. curl_multi_exec\n";
$mh = curl_multi_init();
$ch = curl_init("$base/redirect-to?json=1");
replace_prereq_at_302($ch, $app_calls);
curl_multi_add_handle($mh, $ch);
do {
    curl_multi_exec($mh, $running);
    while (curl_multi_info_read($mh) !== false) {
    }
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running);
var_dump(curl_multi_getcontent($ch));
curl_multi_remove_handle($mh, $ch);

echo "app PREREQ calls: $app_calls\n";
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
== 1. curl_exec
string(22) "{"status":"ok","n":42}"
== 2. curl_multi_exec
string(22) "{"status":"ok","n":42}"
app PREREQ calls: 2
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect-to?json=1"}
curl-1-0 last []
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect-to?json=1"}
curl-2-0 last []
2 subcontext(s), all closed
