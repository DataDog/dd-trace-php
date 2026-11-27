--TEST--
Downstream curl requests are evaluated for every URL libcurl requests with http or https
--DESCRIPTION--
libcurl's scheme for a URL (lib/urlapi.c): the scheme in it, case
insensitively; for a URL without one, CURLOPT_DEFAULT_PROTOCOL or, without
that, a guess from the host name ("ftp." and a few other prefixes; http
otherwise). Transfers whose scheme is http or https are evaluated, with the
URL as libcurl requests it (with the scheme it guessed), in one pre-send
call (W1); other ones are skipped.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
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
$hostport = substr($base, strlen('http://'));
$port = substr($hostport, strrpos($hostport, ':') + 1);
$helper = ce_helper(array_fill(0, 30, 'ok'));
var_dump(rinit());

$cases = [
    'upper-case scheme' => ["HTTP://$hostport/json?n=1", []],
    'no scheme' => ["$hostport/json?n=2", []],
    'no scheme, ftp. host' => ['ftp.example.test:1/x', [CURLOPT_RESOLVE => ['ftp.example.test:1:127.0.0.1']]],
    'no scheme, ftp. host, default protocol http' => ["ftp.example.test:$port/json?n=4",
        [CURLOPT_RESOLVE => ["ftp.example.test:$port:127.0.0.1"], CURLOPT_DEFAULT_PROTOCOL => 'http']],
    'no scheme, default protocol ftp' => ['127.0.0.1:1/x', [CURLOPT_DEFAULT_PROTOCOL => 'ftp']],
    'ftp scheme' => ['FTP://127.0.0.1:1/x', []],
];
foreach ($cases as $name => list($url, $opts)) {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + [CURLOPT_RETURNTRANSFER => true]);
    $res = curl_exec($ch);
    echo "$name: ", $res === false ? 'failed' : $res, "\n";
}

var_dump(rshutdown());
$execs = ce_execs($helper);
ob_start();
ce_print($execs);
ce_check_closed($execs);
echo str_replace(":$port", ':PORT', ob_get_clean());
?>
--EXPECT--
bool(true)
upper-case scheme: {"status":"ok","n":42}
no scheme: {"status":"ok","n":42}
no scheme, ftp. host: failed
no scheme, ftp. host, default protocol http: {"status":"ok","n":42}
no scheme, default protocol ftp: failed
ftp scheme: failed
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"HTTP://127.0.0.1:PORT/json?n=1"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json?n=2"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.headers":{"host":["ftp.example.test:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://ftp.example.test:PORT/json?n=4"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
3 subcontext(s), all closed
