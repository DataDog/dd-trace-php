--TEST--
Downstream curl requests are evaluated natively: handle still in a multi added elsewhere or curl_exec()'d
--DESCRIPTION--
While a handle is in a multi handle, libcurl refuses to add it to another
one (CURLM_ADDED_ALREADY) and to run it with curl_exec() (CURLE_FAILED_INIT).
Neither ends the observation of the running transfer nor starts another
one: the transfer is evaluated to its end in a single subcontext. Once its
multi handle is freed without curl_multi_close(), the handle is in no multi
anymore, and a curl_exec() of it is evaluated. The freed multi made no
WAF call for its transfer (G4.5), still running then: the subcontext of
the second stays open.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('seeing the response head while the transfer runs');
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
use function datadog\appsec\testing\{rinit,rshutdown,curl_downstream_dump,curl_downstream_installed_count};
require __DIR__ . '/inc/curl_eval.php';
// the dumps show the bodies too
datadog\appsec\testing\curl_downstream_collect_all(true);
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 30, 'ok'));
var_dump(rinit());

$mh1 = curl_multi_init();
$mh2 = curl_multi_init();
$ch = curl_init("$base/slow");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
var_dump(curl_multi_add_handle($mh1, $ch));
$t = microtime(true);
do {
    curl_multi_exec($mh1, $running);
    curl_multi_select($mh1, 0.05);
    $d = curl_downstream_dump($ch);
    $head = !empty($d['response']) && $d['response']['head_complete'];
} while ($running && !$head && microtime(true) - $t < 5);
echo 'head received, transfer running: ', cd_yn($head && $running), "\n";

var_dump(curl_multi_add_handle($mh2, $ch) === CURLM_ADDED_ALREADY);
var_dump(curl_exec($ch), curl_errno($ch));
echo 'installed: ', curl_downstream_installed_count(), "\n";

do {
    $status = curl_multi_exec($mh1, $running);
    if ($running) {
        curl_multi_select($mh1, 1.0);
    }
} while ($running && $status == CURLM_OK);
var_dump(curl_multi_getcontent($ch));
curl_multi_remove_handle($mh1, $ch);
cd_dump($ch);

$mh3 = curl_multi_init();
$ch2 = curl_init("$base/slow");
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh3, $ch2);
curl_multi_exec($mh3, $running); // running: nothing is flushed
unset($mh3); // never reported done, never removed
var_dump(curl_exec($ch2));
echo 'installed: ', curl_downstream_installed_count(), "\n";

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
int(0)
head received, transfer running: yes
bool(true)
bool(false)
int(2)
installed: 1
string(10) "0123456789"
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=10, content-type=text/plain]
  resp body: '0123456789' complete=no truncated=no
string(10) "0123456789"
installed: 0
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["10"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-3-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/slow"}
curl-3-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["10"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
curl-2-0 not closed
3 subcontext(s), all closed
