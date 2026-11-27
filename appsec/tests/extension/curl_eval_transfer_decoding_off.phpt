--TEST--
Downstream curl requests: with CURLOPT_HTTP_TRANSFER_DECODING=0 the raw chunk framing is not analysed
--DESCRIPTION--
G5.10 and G7.17: the layer has no special handling for
CURLOPT_HTTP_TRANSFER_DECODING=0. libcurl then passes the chunk framing to
the write function, the collector keeps those bytes as they are (it has no
de-chunker of its own), they fail to parse as JSON and no response body is
sent. The transfer stays observed: its URL (W1) and its response head are
still evaluated, on an easy handle as on a multi handle. With the default
(decoding on) the same response body is analysed.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif_response.php';
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

echo "== 1. transfer decoding off: the app gets the framing\n";
$ch = curl_init("$base/chunked-json");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTP_TRANSFER_DECODING => 0]);
echo json_encode(curl_exec($ch)), "\n";

echo "== 2. transfer decoding on (default)\n";
$ch = curl_init("$base/chunked-json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_exec($ch));

echo "== 3. transfer decoding off, multi handle\n";
$mh = curl_multi_init();
$ch = curl_init("$base/chunked-json");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTP_TRANSFER_DECODING => 0]);
curl_multi_add_handle($mh, $ch);
do {
    curl_multi_exec($mh, $running);
    while (curl_multi_info_read($mh) !== false) {
    }
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running);
echo json_encode(curl_multi_getcontent($ch)), "\n";
curl_multi_remove_handle($mh, $ch);

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
== 1. transfer decoding off: the app gets the framing
"b\r\n{\"status\":\"\r\nb\r\nok\",\"n\":42}\r\n0\r\n\r\n"
== 2. transfer decoding on (default)
string(22) "{"status":"ok","n":42}"
== 3. transfer decoding off, multi handle
"b\r\n{\"status\":\"\r\nb\r\nok\",\"n\":42}\r\n0\r\n\r\n"
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/chunked-json"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-type":["application/json"],"transfer-encoding":["chunked"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/chunked-json"}
curl-2-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-type":["application/json"],"transfer-encoding":["chunked"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/chunked-json"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-type":["application/json"],"transfer-encoding":["chunked"]},"server.io.net.response.status":"200"}
3 subcontext(s), all closed
