--TEST--
Downstream curl requests: no memory guard for the arrays of the response headers
--DESCRIPTION--
The response call turns each header line into PHP strings and arrays, which
for many short lines take many times the bytes of the head (the downstream
server decides it, and the echo pipe of the mock helper holds 64 KiB, so
the sizes stay small). G9.5 and G9.6 allow a memory guard only in the
collector's fit-checked growth: no transfer is skipped and no WAF call left
out for memory. Both points that make a response call are covered: the
completion hook (the last hop) and the PREREQ callback of the next hop (an
intermediate hop, here a redirect). With room for the arrays, both response
calls are made with all the headers and nothing is logged as skipped.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('W2 and the response call');
if (memory_get_usage() === 0) die('skip no ZendMM (e.g. ASan)');
?>
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test_curl_resp_headers_memory.log
datadog.appsec.log_level=debug
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=0
DD_TRACE_ENABLED=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
include __DIR__ . '/inc/logging.php';
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 8, 'ok'));
var_dump(rinit());
ce_execs($helper); // request_init
$execs = [];

foreach (['last hop' => '', 'intermediate hop' => '&redirect=1'] as $name => $q) {
    echo "== $name\n";
    truncate_log();
    $ch = curl_init("$base/manyheaders?n=2000$q");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true]);
    // room for the arrays of the response call
    ini_set('memory_limit', (string)(memory_get_usage(true) + 16 * 1024 * 1024));
    var_dump(curl_exec($ch));
    ini_set('memory_limit', '128M');
    cd_close($ch);
    // the mock helper echoes the calls into a pipe of limited size
    $execs = array_merge($execs, ce_execs($helper));
    echo preg_match_all('/_mem_ok|memory/i', log_contents()),
        " memory line(s) in the log\n";
}

var_dump(rshutdown());
$execs = array_merge($execs, ce_execs($helper));
foreach ($execs as list($id, $last, $data)) {
    echo $id, $last ? ' last' : '', ' ', implode(',', array_keys($data)), "\n";
    if (isset($data['server.io.net.response.headers'])) {
        echo '  response headers: ',
            count($data['server.io.net.response.headers']) > 2000 ? 'all 2000+' : 'few', "\n";
    }
}
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
== last hop
string(2) "ok"
0 memory line(s) in the log
== intermediate hop
string(22) "{"status":"ok","n":42}"
0 memory line(s) in the log
bool(true)
curl-1-0 server.io.net.request.headers,server.io.net.request.method,server.io.net.url
curl-1-0 last server.io.net.response.headers,server.io.net.response.status
  response headers: all 2000+
curl-2-0 server.io.net.request.headers,server.io.net.request.method,server.io.net.url
curl-2-0 last server.io.net.response.headers,server.io.net.response.status
  response headers: all 2000+
curl-2-1 server.io.net.request.method,server.io.net.url
curl-2-1 last server.io.net.response.headers,server.io.net.response.status
  response headers: few
3 subcontext(s), all closed
