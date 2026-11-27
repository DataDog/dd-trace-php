--TEST--
Downstream curl requests: a fatal error in the PREREQ callback leaves libcurl and the multi handle usable
--DESCRIPTION--
G10.15, G9.2, G9.3: the response call of hop 0 of a redirect is made from
the PREREQ callback of hop 1, inside libcurl, and raises a fatal error
(injected, as the memory limit would raise it). It is caught there: PREREQ
aborts hop 1 and libcurl returns normally to curl_multi_exec(), whose hook
raises the fatal error again; the code after it is not reached. libcurl was
not left by a longjmp: afterwards (in a shutdown function) the transfer is
reported done with CURLE_ABORTED_BY_CALLBACK, the handle is removed and
closed, the same multi handle runs a new transfer (no
CURLM_RECURSIVE_API_CALL) whose WAF calls are made, and curl_multi_close()
works. The aborted transfer gets no WAF call after the caught bailout (G9.7):
neither when it is reported done nor when it is removed.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call of hop 0 in PREREQ');
?>
--INI--
datadog.appsec.enabled=1
; PHP 8.5+: no stack trace with the fatal error
fatal_error_backtraces=0
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,curl_eval_fatal_after};
require __DIR__ . '/inc/curl_eval.php';
$helper = null;
register_shutdown_function(function () use (&$helper) {
    global $mh, $ch, $base;
    echo "\n";
    $info = curl_multi_info_read($mh);
    echo 'done: ', $info['msg'] === CURLMSG_DONE ? 'yes' : 'no',
        ', result: ', $info['result'] === CURLE_ABORTED_BY_CALLBACK
            ? 'CURLE_ABORTED_BY_CALLBACK' : $info['result'], "\n";
    echo 'remove: ', curl_multi_remove_handle($mh, $ch), "\n";
    if (PHP_VERSION_ID < 80500) { // deprecated (a no-op since 8.0)
        curl_close($ch);
    }
    $ch = null;
    $ch2 = curl_init("$base/json");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    echo 'add: ', curl_multi_add_handle($mh, $ch2), "\n";
    do {
        $rc = curl_multi_exec($mh, $running);
        if ($rc !== CURLM_OK) {
            echo "curl_multi_exec: $rc\n";
            break;
        }
        if ($running) {
            curl_multi_select($mh, 0.1);
        }
    } while ($running);
    $info = curl_multi_info_read($mh);
    echo 'new transfer result: ', $info['result'], ', body: ',
        curl_multi_getcontent($ch2), "\n";
    curl_multi_remove_handle($mh, $ch2);
    if (PHP_VERSION_ID < 80500) { // deprecated (a no-op since 8.0)
        curl_close($ch2);
    }
    $ch2 = null;
    curl_multi_close($mh);
    $mh = null;
    echo "== WAF calls\n";
    ce_print(ce_execs($helper));
});
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'), false);
rinit();

$mh = curl_multi_init();
$ch = curl_init("$base/redirect");
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
// W1 is the first call; the response call of hop 0, in PREREQ, the second
curl_eval_fatal_after(2);
do {
    curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 0.1);
    }
} while ($running);
echo "not reached\n";
?>
--EXPECTF--
Fatal error: Injected fatal error in downstream request analysis in %s on line %d

done: yes, result: CURLE_ABORTED_BY_CALLBACK
remove: 0
add: 0
new transfer result: 0, body: {"status":"ok","n":42}
== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/redirect"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-2-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
