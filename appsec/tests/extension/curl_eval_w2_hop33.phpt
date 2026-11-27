--TEST--
Downstream curl requests: W2 is made for every redirect hop, also past the hops the collector keeps
--DESCRIPTION--
The collector keeps at most 32 hops and then stops. The pre-send call of a
redirect hop (W2, from CURLOPT_PREREQFUNCTION) runs whatever the state of the
collector, so the redirect targets of hop 32 and later are still checked
(G6.4); the hop index is the count of PREREQ calls (G5.12). Hops the
collector did not keep get no response call: their subcontext, opened by W2,
is closed by the call without data (G6.8). G10.23: W2 on the 33rd hop.
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
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 80, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/redirect-chain?n=34");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 40]);
var_dump(curl_exec($ch));
var_dump(curl_getinfo($ch, CURLINFO_REDIRECT_COUNT));
var_dump(rshutdown());
$execs = ce_execs($helper);
$resp = $closes = [];
foreach ($execs as list($id, $last, $data)) {
    $hop = (int)substr($id, strrpos($id, '-') + 1);
    if (isset($data['server.io.net.url'])) {
        $keys = array_keys($data);
        sort($keys);
        if ($hop >= 1 &&
            $keys !== ['server.io.net.request.method', 'server.io.net.url']) {
            echo "unexpected keys in $id: ", implode(',', $keys), "\n";
        }
        echo $id, $last ? ' last ' : ' ', $data['server.io.net.request.method'],
            ' ', $data['server.io.net.url'], "\n";
    } elseif (isset($data['server.io.net.response.status'])) {
        $resp[] = $hop;
    } elseif (!$data && $last) {
        $closes[] = $hop;
    } else {
        echo "unexpected call: $id\n";
    }
}
echo "response calls: hops ", min($resp), "..", max($resp), " (", count($resp), ")\n";
echo "closed without data: hops ", implode(',', $closes), "\n";
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
string(22) "{"status":"ok","n":42}"
int(34)
bool(true)
curl-1-0 GET http://127.0.0.1:PORT/redirect-chain?n=34
curl-1-1 GET http://127.0.0.1:PORT/redirect-chain?n=33
curl-1-2 GET http://127.0.0.1:PORT/redirect-chain?n=32
curl-1-3 GET http://127.0.0.1:PORT/redirect-chain?n=31
curl-1-4 GET http://127.0.0.1:PORT/redirect-chain?n=30
curl-1-5 GET http://127.0.0.1:PORT/redirect-chain?n=29
curl-1-6 GET http://127.0.0.1:PORT/redirect-chain?n=28
curl-1-7 GET http://127.0.0.1:PORT/redirect-chain?n=27
curl-1-8 GET http://127.0.0.1:PORT/redirect-chain?n=26
curl-1-9 GET http://127.0.0.1:PORT/redirect-chain?n=25
curl-1-10 GET http://127.0.0.1:PORT/redirect-chain?n=24
curl-1-11 GET http://127.0.0.1:PORT/redirect-chain?n=23
curl-1-12 GET http://127.0.0.1:PORT/redirect-chain?n=22
curl-1-13 GET http://127.0.0.1:PORT/redirect-chain?n=21
curl-1-14 GET http://127.0.0.1:PORT/redirect-chain?n=20
curl-1-15 GET http://127.0.0.1:PORT/redirect-chain?n=19
curl-1-16 GET http://127.0.0.1:PORT/redirect-chain?n=18
curl-1-17 GET http://127.0.0.1:PORT/redirect-chain?n=17
curl-1-18 GET http://127.0.0.1:PORT/redirect-chain?n=16
curl-1-19 GET http://127.0.0.1:PORT/redirect-chain?n=15
curl-1-20 GET http://127.0.0.1:PORT/redirect-chain?n=14
curl-1-21 GET http://127.0.0.1:PORT/redirect-chain?n=13
curl-1-22 GET http://127.0.0.1:PORT/redirect-chain?n=12
curl-1-23 GET http://127.0.0.1:PORT/redirect-chain?n=11
curl-1-24 GET http://127.0.0.1:PORT/redirect-chain?n=10
curl-1-25 GET http://127.0.0.1:PORT/redirect-chain?n=9
curl-1-26 GET http://127.0.0.1:PORT/redirect-chain?n=8
curl-1-27 GET http://127.0.0.1:PORT/redirect-chain?n=7
curl-1-28 GET http://127.0.0.1:PORT/redirect-chain?n=6
curl-1-29 GET http://127.0.0.1:PORT/redirect-chain?n=5
curl-1-30 GET http://127.0.0.1:PORT/redirect-chain?n=4
curl-1-31 GET http://127.0.0.1:PORT/redirect-chain?n=3
curl-1-32 GET http://127.0.0.1:PORT/redirect-chain?n=2
curl-1-33 GET http://127.0.0.1:PORT/redirect-chain?n=1
curl-1-34 GET http://127.0.0.1:PORT/redirect-chain?n=0
response calls: hops 0..31 (32)
closed without data: hops 32,33,34
35 subcontext(s), all closed
