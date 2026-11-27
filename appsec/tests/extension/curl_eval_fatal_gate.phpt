--TEST--
Downstream curl requests: a fatal error in the read-function gate leaves libcurl and the multi handle usable
--DESCRIPTION--
G10.15, G9.2, G9.3, G6.7: the request-stage call of the read-function gate
is made from libcurl's read callback, and raises a fatal error (injected, as
the memory limit would raise it). It is caught there: the read function
returns CURL_READFUNC_ABORT and libcurl returns normally to
curl_multi_exec(), whose hook raises the fatal error again; the code after
it is not reached. libcurl was not left by a longjmp: afterwards (in a
shutdown function) the transfer is reported done with
CURLE_ABORTED_BY_CALLBACK, the handle is removed and closed, the same multi
handle runs a new transfer (no CURLM_RECURSIVE_API_CALL) whose W1 is made,
and curl_multi_close() works. The aborted transfer gets no WAF call after
the caught bailout (G9.7): neither when it is reported done nor when it is
removed. The gate runs on every libcurl; the calls of the new transfer after
its W1 depend on it (G6.11) and are not printed.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
?>
--INI--
datadog.appsec.enabled=1
; PHP 8.5+: no stack trace with the fatal error
fatal_error_backtraces=0
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
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
    ce_print(array_filter(ce_execs($helper), function ($e) {
        return strpos($e[0], 'curl-1-') === 0 || ($e[0] === 'curl-2-0' && !$e[1] &&
            isset($e[2]['server.io.net.url']));
    }));
});
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'), false);
rinit();

$mh = curl_multi_init();
$pieces = ['{"a":"fatal"}'];
$ch = curl_init("$base/recv");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_UPLOAD, true);
curl_setopt($ch, CURLOPT_INFILESIZE, 13);
curl_setopt($ch, CURLOPT_READFUNCTION, function ($h, $fd, $len) use (&$pieces) {
    return (string)array_shift($pieces);
});
curl_multi_add_handle($mh, $ch);
// W1 is the first call; the gate's call the second
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
curl-1-0 {"server.io.net.request.headers":{"content-type":["application/json"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"PUT","server.io.net.url":"http://127.0.0.1:PORT/recv"}
curl-2-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
