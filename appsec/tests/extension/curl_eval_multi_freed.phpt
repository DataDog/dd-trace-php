--TEST--
Downstream curl requests are evaluated natively: multi handle freed without curl_multi_close()
--DESCRIPTION--
PHP 8: a CurlMultiHandle freed without curl_multi_close() ends the
observation of its transfers: no WAF call is made from the destructor, not
even the data-less close of the subcontext (G4.5), and our options are
removed. The transfer is still running when the multi handle is freed (the
exec that finds nothing running flushes, G4.18). A new
multi handle, which may get the same address, has nothing to do with them:
its own transfers, and the handles of the freed one added to it, are
evaluated from the start.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
if (PHP_VERSION_ID < 80000) die('skip PHP 8+: multi handles are objects');
$lv = \datadog\appsec\testing\curl_linkage_info()['libcurl_version_num'];
// libcurl 8.1.0 to 8.9.x crash (without the analysis too) when an easy
// handle of a multi freed without curl_multi_close() is used again: the
// multi's unlink_all_msgsent_handles() leaves it pointing into the freed
// multi's DNS cache (rewritten in 8.10.0)
if ($lv >= 0x080100 && $lv < 0x080a00) die('skip libcurl 8.1.0-8.9.x crashes there');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_installed_count};
require __DIR__ . '/inc/curl_eval.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 30, 'ok'));
var_dump(rinit());

function run_multi($mh)
{
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status == CURLM_OK);
}

$mh = curl_multi_init();
$ch = curl_init("$base/slow");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
curl_multi_exec($mh, $running); // started and still running: no flush
echo 'installed: ', curl_downstream_installed_count(), "\n";
unset($mh);
echo 'installed after the multi is freed: ', curl_downstream_installed_count(), "\n";

$mh = curl_multi_init();
$ch2 = curl_init("$base/xml");
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch2);
var_dump(curl_multi_add_handle($mh, $ch));
// started by the first exec, not by the add
echo 'installed: ', curl_downstream_installed_count(), "\n";
run_multi($mh);
var_dump(curl_multi_getcontent($ch), curl_multi_getcontent($ch2));
curl_multi_remove_handle($mh, $ch);
curl_multi_remove_handle($mh, $ch2);
echo 'installed: ', curl_downstream_installed_count(), "\n";

var_dump(rshutdown());
$execs = ce_execs($helper);
$groups = [];
foreach ($execs as $e) {
    $groups[$e[0]][] = $e;
}
ksort($groups);
ce_print(array_merge(...array_values($groups)));
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
installed: 1
installed after the multi is freed: 0
int(0)
installed: 0
string(10) "0123456789"
string(37) "<doc><item attr="v">text</item></doc>"
installed: 0
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/xml"}
curl-2-0 last {"server.io.net.response.body":{"doc":[{"item":[{"@attr":"v"},"text"]}]},"server.io.net.response.headers":{"connection":["close"],"content-length":["37"],"content-type":["application/xml"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["10"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-1-0 not closed
3 subcontext(s), all closed
