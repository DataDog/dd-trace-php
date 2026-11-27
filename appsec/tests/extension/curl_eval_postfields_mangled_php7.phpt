--TEST--
Downstream curl requests: protected and private properties of an object CURLOPT_POSTFIELDS (PHP 7)
--DESCRIPTION--
ext/curl 7 sends the properties of an object CURLOPT_POSTFIELDS by the C
string of their key, so the mangled name of a protected or private property
(which starts with a NUL byte) is an empty field name, several of them being
several fields of that name. The snapshot recorded by the hook has the same
names; the array body can hold one field per name, the last one winning.
--SKIPIF--
<?php
include __DIR__ . '/inc/curl_skipif.php';
if (PHP_VERSION_ID >= 80000) die('skip PHP 7 only');
if (PHP_VERSION_ID < 70400) die('skip PHP 7.4+: before, ext/curl asserts on declared properties (debug builds)');
curl_skipif_needs_prereq('the response call');
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
$base = cd_server_start();
$helper = ce_helper(array_fill(0, 4, 'ok'));

class Mangled {
    protected $p = 'x';
    private $q = 'y';
    public $pub = 'z';
}

var_dump(rinit());
$ch = curl_init("$base/len");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, new Mangled);
echo curl_exec($ch) !== false ? "sent\n" : "failed\n";
var_dump(rshutdown());
$execs = ce_execs($helper);
ce_print($execs);
ce_check_closed($execs);
?>
--EXPECTF--
bool(true)
sent
bool(true)
curl-1-0 {"server.io.net.request.body":{"":"y","pub":"z"},"server.io.net.request.headers":{"content-type":["multipart/form-data"],"host":["127.0.0.1:PORT"]},"server.io.net.request.method":"POST","server.io.net.url":"http://127.0.0.1:PORT/len"}
curl-1-0 last {"server.io.net.response.headers":{"connection":["close"],"content-length":["%d"],"content-type":["text/plain"]},"server.io.net.response.status":"200"}
1 subcontext(s), all closed
