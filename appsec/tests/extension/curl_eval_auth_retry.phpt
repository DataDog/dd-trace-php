--TEST--
Downstream curl requests: an authentication retry is a hop
--DESCRIPTION--
With CURLAUTH_DIGEST, libcurl first sends the request without credentials,
gets a 401 with the challenge, and sends it again with an Authorization
header. Like a redirect, the retry is a hop of its own: libcurl calls
CURLOPT_PREREQFUNCTION for it (two PREREQ calls in all, finding Q6.8), the
401 gets its response call there, and the retry gets W2 with the URL and
method only (no Authorization header, so a rule on its absence can match),
in a subcontext of its own. The 401 body is never sent: only the last hop's
body is.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
// without it, no hop after the first is evaluated (G6.11)
curl_skipif_needs_prereq('the expected calls');
?>
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_DOWNSTREAM_BODY_ANALYSIS_SAMPLE_RATE=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
require __DIR__ . '/inc/curl_eval.php';
$log = tempnam(sys_get_temp_dir(), 'cdlog');
$base = cd_server_start($log);
$helper = ce_helper(array_fill(0, 4, 'ok'));
var_dump(rinit());
$ch = curl_init("$base/digest");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
curl_setopt($ch, CURLOPT_USERPWD, 'user:secret');
var_dump(curl_exec($ch));
var_dump(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
echo file_get_contents($log);
@unlink($log);
?>
--EXPECTF--
bool(true)
string(22) "{"status":"ok","n":42}"
int(200)
bool(true)
curl-1-0 {"server.io.net.request.headers":{"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/digest"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["12"],"content-type":["text/plain"],"www-authenticate":["Digest realm=\"test\", qop=\"auth\", nonce=\"dcd98b7102dd2f0e8b11d0f600bfb0c093\", opaque=\"5ccc069c403ebaf9f0171e9517f40e41\""]},"server.io.net.response.status":"401"}
curl-1-1 {"server.io.net.request.method":"GET","server.io.net.url":"http://127.0.0.1:PORT/digest"}
curl-1-1 last {"server.io.net.response.body":{"n":42,"status":"ok"},"server.io.net.response.headers":{"connection":["close"],"content-length":["22"],"content-type":["application/json"]},"server.io.net.response.status":"200"}
2 subcontext(s), all closed
GET /digest
GET /digest
