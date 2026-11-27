--TEST--
Downstream curl requests through an HTTP proxy tunnel (CONNECT) are evaluated as requests to the origin
--DESCRIPTION--
The CONNECT head that sets up a proxy tunnel arrives through the interposed
header function (G5.11), before the request libcurl sends through the tunnel. That exchange is not a hop: neither the CONNECT
request headers (with the proxy's credentials) nor the proxy's responses (a
407, "200 Connection established") are evaluated, and the request through the
tunnel is hop 0 (evaluated from the options before it, with its response).
This covers tunnels asked for with CURLOPT_HTTPPROXYTUNNEL, a 407 followed by
a new CONNECT with credentials, and https:// URLs through a proxy, set with
CURLOPT_PROXY or taken by libcurl from the https_proxy environment variable.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
curl_skipif_needs_prereq('the response call');
if (!(curl_version()['features'] & CURL_VERSION_SSL)) die('skip libcurl without TLS');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=10
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
// the dumps show the bodies too
datadog\appsec\testing\curl_downstream_collect_all(true);
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 40, 'ok'));
var_dump(rinit());

echo "== 1. tunnel to an http:// origin, with proxy credentials\n";
$ch = curl_init('http://example.test/json?n=1');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => $base,
    CURLOPT_HTTPPROXYTUNNEL => true, CURLOPT_PROXYUSERPWD => 'user:secret']);
var_dump(curl_exec($ch));
cd_dump($ch);

echo "== 2. 407 from the proxy, then CONNECT with credentials\n";
$ch = curl_init('http://auth.example.test/json?n=2');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => $base,
    CURLOPT_HTTPPROXYTUNNEL => true, CURLOPT_PROXYUSERPWD => 'user:secret',
    CURLOPT_PROXYAUTH => CURLAUTH_ANY]);
var_dump(curl_exec($ch));
cd_dump($ch);

echo "== 3. https:// origin through CURLOPT_PROXY\n";
$ch = curl_init('https://example.test/json?n=3');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => $base,
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
var_dump(curl_exec($ch));
cd_dump($ch);

echo "== 4. https:// origin through the proxy in https_proxy\n";
putenv("https_proxy=$base");
$ch = curl_init('https://example.test/json?n=4');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
var_dump(curl_exec($ch));
putenv('https_proxy');
cd_dump($ch);

var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
echo "== server log\n";
echo file_get_contents($log);
@unlink($log);
?>
--EXPECT--
bool(true)
== 1. tunnel to an http:// origin, with proxy credentials
string(22) "{"status":"ok","n":42}"
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
== 2. 407 from the proxy, then CONNECT with credentials
string(22) "{"status":"ok","n":42}"
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
== 3. https:// origin through CURLOPT_PROXY
string(22) "{"status":"ok","n":42}"
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
== 4. https:// origin through the proxy in https_proxy
string(22) "{"status":"ok","n":42}"
observed=yes reason=NULL installed=no hops=1
  resp: 200 interim=0 head_complete=yes
  resp headers: [connection=close, content-length=22, content-type=application/json]
  resp body: '{"status":"ok","n":42}' complete=yes truncated=no
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["example.test"]},"server.io.net.request.method":"GET","server.io.net.url":"http://example.test/json?n=1"}
curl-1-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-2-0 {"server.io.net.request.headers":{"host":["auth.example.test"]},"server.io.net.request.method":"GET","server.io.net.url":"http://auth.example.test/json?n=2"}
curl-2-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-3-0 {"server.io.net.request.headers":{"host":["example.test"]},"server.io.net.request.method":"GET","server.io.net.url":"https://example.test/json?n=3"}
curl-3-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
curl-4-0 {"server.io.net.request.headers":{"host":["example.test"]},"server.io.net.request.method":"GET","server.io.net.url":"https://example.test/json?n=4"}
curl-4-0 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
4 subcontext(s), all closed
== server log
CONNECT example.test:80
GET /json?n=1
CONNECT auth.example.test:80
CONNECT auth.example.test:80
GET /json?n=2
CONNECT example.test:443
GET /json?n=3
CONNECT example.test:443
GET /json?n=4
