--TEST--
Downstream curl requests: the response status is a decimal string, in a call of its own
--DESCRIPTION--
RFC-1062 defines server.io.net.response.status as a string. The response call
of a hop carries it with the response headers; it carries no
server.io.net.url (the URL reaches that stage only through the subcontext
shared with the request call), no request address and no method. The calls are
recorded verbatim by the mock helper, so the msgpack type of the status shows.
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
$helper = ce_helper(array_fill(0, 6, 'ok'));
var_dump(rinit());
foreach (['/json', '/status/404', '/status/204'] as $path) {
    $ch = curl_init("$base$path");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
}
var_dump(rshutdown());
$execs = ce_execs($helper);
foreach ($execs as list($id, $last, $data)) {
    if (!$last) {
        continue; // the request call
    }
    echo $id, ': ', implode(',', array_keys($data)), "\n";
    var_dump($data['server.io.net.response.status']);
}
ce_check_closed($execs);
?>
--EXPECT--
bool(true)
bool(true)
curl-1-0: server.io.net.response.headers,server.io.net.response.status
string(3) "200"
curl-2-0: server.io.net.response.headers,server.io.net.response.status
string(3) "404"
curl-3-0: server.io.net.response.headers,server.io.net.response.status
string(3) "204"
3 subcontext(s), all closed
