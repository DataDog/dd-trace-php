--TEST--
Downstream curl requests: below libcurl 7.80 only the request side of hop 0 is analysed
--DESCRIPTION--
G6.11, G10.16: without CURLOPT_PREREQFUNCTION the layer installs no PREREQ
callback and no header or write function (our forwarders are never called,
no PREREQ call is seen), so no response data is collected. W1 is made for
hop 0 and the read-function gate analyses hop 0's body; hop 0's subcontext is
closed after the transfer by the call without data (G6.8), and a redirect
target (hop 1) gets no W2 and no call at all, though it is requested. The
read slot holds our forwarder (the gate's), the others are not used. The
last transfer is blocked by W1: it is never sent and the app's request is
aborted. The gate's own block below 7.80 is asserted by
curl_eval_no_prereq_gate_block.phpt.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (\datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'] >= 0x075000) {
    die('skip libcurl 7.80.0+ has CURLOPT_PREREQFUNCTION: the full analysis runs (this test is for libcurl below 7.80)');
}
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=100
--FILE--
<?php
use function datadog\appsec\testing\{rinit,curl_downstream_dump,curl_downstream_slots};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$helper = null;
$transcript = '';
// registered before the server is started, so it runs before the server is
// stopped
register_shutdown_function(function () use (&$helper, &$transcript, $log) {
    global $cd_server_base;
    echo "\n== before the block\n", $transcript;
    // the server serves one connection at a time, in order: once this one
    // is served, the earlier ones were logged
    file_get_contents("$cd_server_base/json?sync");
    echo "\n== WAF calls\n";
    $execs = ce_execs($helper);
    ce_print($execs);
    // the blocked transfer gets no closing call (G6.8, G6.9)
    ce_check_closed(array_filter($execs, function ($e) {
        return $e[0] !== 'curl-4-0';
    }));
    echo "== server log\n", file_get_contents($log);
    @unlink($log);
});
$base = cd_server_start($log);
$helper = ce_helper(array_merge(array_fill(0, 7, 'ok'), ['block']), false);
rinit();
// the block needs headers not sent yet: what comes before it is printed at
// shutdown
ob_start();

function show($ch, $label)
{
    $d = curl_downstream_dump($ch);
    echo "$label: observed=", cd_yn($d['observed']), ' PREREQ calls=', $d['hops'],
        ' header calls=', $d['slot_calls']['header'],
        ' write calls=', $d['slot_calls']['write'],
        ' response=', json_encode($d['response']), "\n";
}

echo "== GET, with the app's header and write functions\n";
$ch = curl_init("$base/json");
$slots = null;
$heads = $body = '';
curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($h, $s) use (&$heads) {
    $heads .= $s;
    return strlen($s);
});
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($h, $s) use (&$body, &$slots) {
    $slots = $slots ?: curl_downstream_slots($h);
    $body .= $s;
    return strlen($s);
});
var_dump(curl_exec($ch), strpos($heads, "HTTP/1.1 200 OK\r\n") === 0, $body);
echo 'slots during the transfer: ', json_encode($slots), "\n";
show($ch, 'GET');

echo "== redirect\n";
$ch = curl_init("$base/redirect");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
var_dump(curl_exec($ch));
show($ch, 'redirect');

echo "== PUT through the gate\n";
$pieces = ['{"a":"from ', 'readfunction"}'];
$ch = curl_init("$base/recv");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_INFILESIZE, 25);
$gate = [];
curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces, &$gate) {
    $gate[] = curl_downstream_dump($h)['gate'];
    return (string)array_shift($pieces);
});
var_dump(curl_exec($ch));
echo 'gate during the reads: ', implode(', ', $gate), "\n";
show($ch, 'PUT');

$transcript = ob_get_clean();
$ch = curl_init("$base/json?attack=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
echo "not reached\n";
?>
--EXPECTHEADERS--
Status: 403 Forbidden
Content-type: application/json
--EXPECTF--
{"errors":[{"title":"You've been blocked","detail":"Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}],"security_response_id":""}
Warning: curl_exec(): Datadog blocked the request and presented a static error page. No action required. Security Response ID:  in %s on line %d

== before the block
== GET, with the app's header and write functions
bool(true)
bool(true)
string(22) "{"status":"ok","n":42}"
slots during the transfer: {"write":"unused","header":"unused","read":"ours","data":"own"}
GET: observed=yes PREREQ calls=0 header calls=0 write calls=0 response=null
== redirect
string(22) "{"status":"ok","n":42}"
redirect: observed=yes PREREQ calls=0 header calls=0 write calls=0 response=null
== PUT through the gate
string(17) "received 25 bytes"
gate during the reads: filling, filling
PUT: observed=yes PREREQ calls=0 header calls=0 write calls=0 response=null

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-0 last []
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect"}
curl-2-0 last []
curl-3-0 {"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/recv"}
curl-3-0 {"server.io.net.request.body":{"a":"from readfunction"}}
curl-3-0 last []
curl-4-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json?attack=1"}
3 subcontext(s), all closed
== server log
GET /json
GET /redirect
GET /json
PUT /recv
body 25 bytes
GET /json?sync
