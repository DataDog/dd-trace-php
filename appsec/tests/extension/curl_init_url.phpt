--TEST--
Downstream curl requests: curl_init($url) alone gives the URL of the request
--DESCRIPTION--
The URL passed to curl_init() reaches libcurl without a curl_setopt() call, so
the hook records it at curl_init() time: a transfer made with no setopt at all
(the output goes to stdout, not to a variable) has its URL in the WAF call.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 2, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/json?init=1");
curl_exec($ch);
echo "\n";
var_dump(rshutdown());
$execs = ce_execs($helper);
foreach ($execs as list($id, $last, $data)) {
    if (!$last) {
        echo preg_replace('/:\d+/', ':PORT', $data['server.io.net.url']), ' ',
            $data['server.io.net.request.method'], "\n";
    }
}
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
{"status":"ok","n":42}
bool(true)
http://127.0.0.1:PORT/json?init=1 GET
1 subcontext(s), all closed
