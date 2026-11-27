--TEST--
Downstream curl requests: a blocking response verdict in a curl_multi transfer is ignored
--DESCRIPTION--
The response call is made when the transfer is reported done
(curl_multi_info_read) and gets a blocking verdict; it is not acted on: the
app gets the content. curl_multi_exec() itself only runs libcurl.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\rinit;
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(['ok', 'ok', 'block', 'ok'], false);
ce_print_at_shutdown($helper, $log);
rinit();
$mh = curl_multi_init();
$ch = curl_init("$base/json");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_multi_add_handle($mh, $ch);
do {
    curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 1.0);
    }
} while ($running);
$info = curl_multi_info_read($mh);
echo "done: ", $info['msg'] === CURLMSG_DONE ? 'yes' : 'no', "\n";
echo "content: ", curl_multi_getcontent($ch), "\n";
?>
--EXPECT--
done: yes
content: {"status":"ok","n":42}

== WAF calls
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/json"}
curl-1-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
== server log
GET /json
