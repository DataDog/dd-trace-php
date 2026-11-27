--TEST--
Downstream curl requests: W2 is made for a redirect hop after the collector failed
--DESCRIPTION--
The collector stops for good when an allocation is refused (the memory
limit). The pre-send call of the redirect hop (W2, from
CURLOPT_PREREQFUNCTION) still runs, so the redirect target is checked
(G6.4). Hops of which the collector holds no complete response head get no
response call; their subcontexts are closed by the call without data
(G6.8). G10.23: W2 after a collector failure. The refusal is forced from hop
0's first allocation on by a testing function.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('W2');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_coll_refuse};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/redirect");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true]);
curl_downstream_coll_refuse(true);
var_dump(curl_exec($ch));
curl_downstream_coll_refuse(false);
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
string(22) "{"status":"ok","n":42}"
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect"}
curl-1-0 last []
curl-1-1 {"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-1 last []
2 subcontext(s), all closed
