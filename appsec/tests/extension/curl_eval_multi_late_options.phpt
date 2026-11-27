--TEST--
Downstream curl requests: a multi transfer's W1 is made at its first curl_multi_exec
--DESCRIPTION--
libcurl reads a handle's options at the first curl_multi_perform, not when it
is added (G6.3, G4.17): options set between curl_multi_add_handle() and the
first curl_multi_exec() are those of hop 0, so W1 (URL, method, headers and
body) is made then, from them. Nothing is evaluated by the add itself. A
handle removed again before the first exec starts no transfer at all.
Options set after the first exec are not seen: the single W1 of hop 0 keeps
the old values and is not repeated.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=100
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 12, 'ok'));
var_dump(rinit());
ce_execs($helper); // request_init

function run_multi($mh) {
    do {
        curl_multi_exec($mh, $running);
        while (curl_multi_info_read($mh) !== false) {
        }
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running);
}

$mh = curl_multi_init();
$ch = curl_init("$base/echo");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
// set after the add, before the first exec
curl_setopt($ch, CURLOPT_URL, "$base/late");
curl_setopt($ch, CURLOPT_POSTFIELDS, '{"late":1}');
curl_setopt($ch, CURLOPT_HTTPHEADER,
    ['Content-Type: application/json', 'X-Late: yes']);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
run_multi($mh);
$calls = ce_execs($helper);
ce_print([$calls[0]]);
echo "server got: ", explode(' ', trim(file_get_contents($log)))[0], "\n";
curl_multi_remove_handle($mh, $ch);
ce_check_closed($calls);

// set after the first exec: not seen, W1 is not repeated
$ch3 = curl_init("$base/slow");
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch3);
curl_multi_exec($mh, $running);
curl_setopt($ch3, CURLOPT_URL, "$base/after");
curl_setopt($ch3, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch3, CURLOPT_POSTFIELDS, '{"after":1}');
curl_setopt($ch3, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
run_multi($mh);
$calls = ce_execs($helper);
echo "calls for the transfer: ", count($calls), "\n";
ce_print($calls);
curl_multi_remove_handle($mh, $ch3);
ce_check_closed($calls);

// removed before the first exec: nothing starts, nothing is evaluated
$ch2 = curl_init("$base/never");
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch2);
curl_multi_remove_handle($mh, $ch2);
run_multi($mh);
var_dump(rshutdown());
echo "calls for the removed handle: ", count(ce_execs($helper)), "\n";
@unlink($log);
?>
--EXPECTF--
bool(true)
curl-1-0 {"server.io.net.request.body":{"late":1},"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"],"x-late":["yes"]},"server.io.net.request.method":"PATCH","server.io.net.url":"http://127.0.0.1:PORT/late"}
server got: PATCH
1 subcontext(s), all closed
calls for the transfer: 2
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["10"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
1 subcontext(s), all closed
bool(true)
calls for the removed handle: 0
