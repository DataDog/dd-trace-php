--TEST--
Downstream curl requests: a later hop to a scheme other than http(s) gets no WAF call
--DESCRIPTION--
Hop 0 is evaluated (W1) and its redirect response gets its response call. The
redirect leads to a dict:// URL (a socket of this process that accepts and
stays silent): the hop's PREREQ reports that URL, which is out of scope
(G7.1), so neither W2 nor the response call of that hop is made, and no
subcontext curl-1-1 exists. The hop is logged at debug level.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('hops >= 1');
?>
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test_curl_eval_redirect_scheme.log
datadog.appsec.log_level=debug
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
include __DIR__ . '/inc/logging.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 10, 'ok'));
$sock = stream_socket_server('tcp://127.0.0.1:0');
$sock_port = substr(stream_socket_get_name($sock, false), strlen('127.0.0.1:'));
truncate_log();
var_dump(rinit());

$ch = curl_init("$base/redirect-to?u=dict://127.0.0.1:$sock_port/d:word");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 1, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_DICT]);
var_dump(curl_exec($ch));

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
echo preg_match_all('/\[debug\] Not evaluating hop 1 of a curl transfer: scheme of dict:\/\/127\.0\.0\.1:\d+\/d:word/',
    log_contents()), " skipped hop line(s)\n";
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect-to?u=dict://127.0.0.1:PORT/d:word"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["11"],"content-type":["text/plain"],"location":["dict://127.0.0.1:PORT/d:word"]},"server.io.net.response.status":"302"}
1 subcontext(s), all closed
1 skipped hop line(s)
